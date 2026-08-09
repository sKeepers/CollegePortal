<?php

namespace App\Services\FisIntegration\Xml;

use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;
use App\Models\FisOutboundPackage;
use App\Models\Person;
use App\Services\Admissions\PersonDocumentService;
use XMLWriter;

/**
 * Раздел «Applications» — заявления поступающих.
 *
 * Самый требовательный раздел схемы: заявление без документа, удостоверяющего
 * личность, и без конкурсной группы схему не проходит. Поэтому здесь ничего не
 * додумывается — каждое недостающее сведение становится причиной отказа с
 * указанием заявления и поля.
 */
class ApplicationsWriter
{
    /**
     * Документ об образовании ложится в свой элемент схемы. Соответствие ведём
     * по коду справочника портала, а не по `metadata.fis_type`: один и тот же
     * тип `TSchoolCertificateDocument` схема использует для двух разных
     * элементов — аттестата об основном общем и о среднем (полном) общем.
     *
     * @var array<string, string>
     */
    private const EDUCATION_DOCUMENT_ELEMENTS = [
        'basic_general_certificate' => 'SchoolCertificateBasicDocument',
        'secondary_general_certificate' => 'SchoolGeneralCertificateDocument',
        'spo_diploma' => 'MiddleEduDiplomaDocument',
        'npo_diploma' => 'BasicDiplomaDocument',
        'academic_certificate' => 'AcademicDiplomaDocument',
        'foreign_education_document' => 'ForeignStateEduDocument',
        'other_education' => 'EduCustomDocument',
    ];

    public function __construct(
        private readonly FisReferenceResolver $references,
        private readonly PersonDocumentService $documents,
    ) {
    }

    /** @return array<string, int> */
    public function write(XMLWriter $writer, XmlFieldWriter $fields, CompositionBlockers $blockers, FisOutboundPackage $package): array
    {
        if (! $package->admission_year) {
            $blockers->add(
                'admission_year_missing',
                'admission_year',
                'Не указан год приёмной кампании: непонятно, какие заявления выгружать.',
            );

            return ['applications' => 0];
        }

        $applications = AdmissionApplication::query()
            ->foundation()
            ->active()
            ->with([
                'applicant.person',
                'statusItem',
                'choices.educationProgram',
                'documentSet.identityDocument.documentType',
                'documentSet.educationDocument.documentType',
            ])
            ->where('admission_year', $package->admission_year)
            ->orderBy('id')
            ->get()
            ->reject(fn (AdmissionApplication $application): bool => $application->isDraft());

        if ($applications->isEmpty()) {
            $blockers->add(
                'no_source_data',
                'Applications',
                'За '.$package->admission_year.' год нет зарегистрированных заявлений приёмной комиссии.',
            );

            return ['applications' => 0];
        }

        $writer->startElement('Applications');

        foreach ($applications as $application) {
            $fields->context('Заявление '.($application->application_number ?: '#'.$application->id));
            $writer->startElement('Application');
            $this->writeApplication($writer, $fields, $blockers, $package, $application);
            $writer->endElement();
        }

        $writer->endElement();
        $fields->context('');

        return ['applications' => $applications->count()];
    }

    private function writeApplication(
        XMLWriter $writer,
        XmlFieldWriter $fields,
        CompositionBlockers $blockers,
        FisOutboundPackage $package,
        AdmissionApplication $application,
    ): void {
        $person = $application->applicant?->person;
        $metadata = $application->metadata ?: [];

        $fields->requiredText('UID', $this->applicationUid($application), 200);
        $fields->bool('FromEPGU', (bool) ($metadata['from_epgu'] ?? false));
        $fields->requiredText('ApplicationNumber', $application->application_number, 50, 'Не заполнен номер заявления.');

        $writer->startElement('Entrant');
        $this->writeEntrant($writer, $fields, $blockers, $application, $person);
        $writer->endElement();

        $fields->requiredDateTime(
            'RegistrationDate',
            $application->registered_at ?: $application->submitted_at,
            'Не заполнена дата регистрации заявления.',
        );
        $fields->bool('NeedHostel', (bool) ($metadata['need_hostel'] ?? false));
        $fields->requiredInt(
            'StatusID',
            $this->references->fromReferenceItem($application->status_id, 'ApplicationStatusID', $package->environment),
            'Статус заявления не сопоставлен со справочником ФИС №4.',
        );
        $fields->optionalText('StatusComment', $application->comment, 4000);

        $after11 = $this->after11($application->education_base);
        if ($after11 !== null) {
            $fields->bool('After11', $after11);
        }

        $this->writeFinSourceAndEduForms($writer, $fields, $blockers, $package, $application);
        $this->writeApplicationDocuments($writer, $fields, $blockers, $package, $application, $person);
    }

    private function writeEntrant(
        XMLWriter $writer,
        XmlFieldWriter $fields,
        CompositionBlockers $blockers,
        AdmissionApplication $application,
        ?Person $person,
    ): void {
        if (! $person) {
            $blockers->add(
                'person_missing',
                'Entrant',
                'У заявления нет связанной личной карточки: выгружать нечего.',
                'Заявление #'.$application->id,
            );

            return;
        }

        $fields->requiredText('UID', $this->entrantUid($application, $person), 200);
        $fields->requiredText('LastName', $person->last_name ?: $application->last_name, 250, 'Не заполнена фамилия поступающего.');
        $fields->requiredText('FirstName', $person->first_name ?: $application->first_name, 250, 'Не заполнено имя поступающего.');
        $fields->optionalText('MiddleName', $person->middle_name ?: $application->middle_name, 250);
        $fields->requiredInt(
            'GenderID',
            $this->references->genderId($person->gender),
            'Пол поступающего не сопоставлен со справочником ФИС №5 (FIS_DICT_GENDER_MALE / FIS_DICT_GENDER_FEMALE).',
        );
        $fields->optionalText('SNILS', $person->snils, 14);

        // Элемент обязателен, но оба его потомка необязательны: пустой блок
        // схему проходит, а вот отсутствующий — нет.
        $writer->startElement('EmailOrMailAddress');
        $fields->optionalText('Email', $person->email ?: $application->email, 150);
        $writer->endElement();
    }

    private function writeFinSourceAndEduForms(
        XMLWriter $writer,
        XmlFieldWriter $fields,
        CompositionBlockers $blockers,
        FisOutboundPackage $package,
        AdmissionApplication $application,
    ): void {
        $choices = $application->choices->sortBy('priority');

        if ($choices->isEmpty()) {
            $blockers->add(
                'choices_missing',
                'FinSourceAndEduForms',
                'В заявлении не выбрана ни одна образовательная программа.',
                'Заявление '.($application->application_number ?: '#'.$application->id),
            );

            return;
        }

        $written = 0;
        $writer->startElement('FinSourceAndEduForms');

        foreach ($choices as $choice) {
            $uid = $this->references->competitiveGroupUid($choice->education_program_id, $package->environment);

            if ($uid === null) {
                $blockers->add(
                    'competitive_group_missing',
                    'FinSourceEduForm.CompetitiveGroupUID',
                    'Для образовательной программы «'.($choice->educationProgram?->name ?: '#'.$choice->education_program_id).'» не задан UID конкурсной группы ФИС. Конкурс создаётся в самой ФИС, а его UID заносится сопоставлением.',
                    'Заявление '.($application->application_number ?: '#'.$application->id),
                );

                continue;
            }

            $writer->startElement('FinSourceEduForm');
            $fields->requiredText('CompetitiveGroupUID', $uid, 200);
            $writer->endElement();
            $written++;
        }

        $writer->endElement();

        if ($written === 0) {
            $blockers->add(
                'competitive_group_missing',
                'FinSourceAndEduForms',
                'Ни одно условие приёма не удалось собрать: без UID конкурсной группы заявление схему не проходит.',
                'Заявление '.($application->application_number ?: '#'.$application->id),
            );
        }
    }

    private function writeApplicationDocuments(
        XMLWriter $writer,
        XmlFieldWriter $fields,
        CompositionBlockers $blockers,
        FisOutboundPackage $package,
        AdmissionApplication $application,
        ?Person $person,
    ): void {
        $identity = $application->documentSet?->identityDocument
            ?: $this->documents->currentIdentity($person?->getKey());
        $education = $application->documentSet?->educationDocument
            ?: $this->documents->currentEducation($person?->getKey());

        $writer->startElement('ApplicationDocuments');

        if ($identity) {
            $writer->startElement('IdentityDocument');
            $this->writeIdentityDocument($fields, $identity, $person);
            $writer->endElement();
        } else {
            $blockers->add(
                'identity_document_missing',
                'ApplicationDocuments.IdentityDocument',
                'Нет документа, удостоверяющего личность. Схема ФИС требует его в каждом заявлении.',
                'Заявление '.($application->application_number ?: '#'.$application->id),
            );
        }

        if ($education) {
            $this->writeEducationDocuments($writer, $fields, $blockers, $package, $application, $education);
        }

        $writer->endElement();
    }

    private function writeIdentityDocument(XmlFieldWriter $fields, IdentityDocument $document, ?Person $person): void
    {
        $fields->requiredText('UID', $document->fis_uid ?: 'identity-document-'.$document->getKey(), 200);
        $fields->optionalText('LastName', $this->personalName($person?->last_name), 250);
        $fields->optionalText('FirstName', $this->personalName($person?->first_name), 250);
        $fields->optionalText('MiddleName', $this->personalName($person?->middle_name), 250);
        $fields->optionalText('DocumentSeries', $document->series, 20);
        $fields->requiredText('DocumentNumber', $document->number, 100, 'Не заполнен номер документа, удостоверяющего личность.');
        $fields->optionalText('SubdivisionCode', $this->subdivisionCode($document->subdivision_code), 7);
        $fields->requiredDate('DocumentDate', $document->issue_date, 'Не заполнена дата выдачи документа, удостоверяющего личность.');
        $fields->optionalText('DocumentOrganization', $document->issued_by, 500);
        $fields->requiredInt(
            'IdentityDocumentTypeID',
            $document->fis_identity_document_type_id,
            'Тип документа, удостоверяющего личность, не сопоставлен со справочником ФИС №22.',
        );
        $fields->requiredInt(
            'NationalityTypeID',
            $document->fis_nationality_type_id,
            'Гражданство поступающего не сопоставлено со справочником ФИС №7.',
        );
        $fields->requiredDate('BirthDate', $person?->birth_date, 'Не заполнена дата рождения поступающего.');
        $fields->optionalText('BirthPlace', $person?->place_birth, 250);
        $fields->requiredInt(
            'ReleaseCountryID',
            $document->fis_release_country_id,
            'Страна выдачи документа не сопоставлена со справочником ФИС №7.',
        );
        $fields->requiredText('ReleasePlace', $document->release_place, 250, 'Не заполнено место выдачи документа, удостоверяющего личность.');
    }

    private function writeEducationDocuments(
        XMLWriter $writer,
        XmlFieldWriter $fields,
        CompositionBlockers $blockers,
        FisOutboundPackage $package,
        AdmissionApplication $application,
        EducationDocument $document,
    ): void {
        $code = (string) $document->documentType?->code;
        $element = self::EDUCATION_DOCUMENT_ELEMENTS[$code] ?? null;

        if ($element === null) {
            $blockers->add(
                'education_document_type_unmapped',
                'EduDocuments',
                'Тип документа об образовании «'.($document->documentType?->name ?: $code ?: 'не указан').'» не соотнесён с элементом схемы ФИС.',
                'Заявление '.($application->application_number ?: '#'.$application->id),
            );

            return;
        }

        $writer->startElement('EduDocuments');
        $writer->startElement('EduDocument');
        $writer->startElement($element);

        $fields->requiredText('UID', $document->fis_uid ?: 'education-document-'.$document->getKey(), 200);
        $fields->optionalDate('OriginalReceivedDate', $document->original_received_at);
        $fields->optionalText('DocumentSeries', $document->series, 20);

        if ($element === 'ForeignStateEduDocument') {
            $fields->requiredText('DocumentNumber', $document->number, 100, 'Не заполнен номер документа об образовании.');
            $fields->requiredInt('CountryID', $document->fis_country_id, 'Страна документа об образовании не сопоставлена со справочником ФИС №7.');
            $fields->optionalText('DocumentOrganization', $document->document_organization, 500);
            $fields->optionalFloat('GPA', $document->average_score);
            $writer->endElement();
            $writer->endElement();
            $writer->endElement();

            return;
        }

        if ($element === 'EduCustomDocument') {
            $fields->optionalText('DocumentNumber', $document->number, 100);
            $fields->requiredText('DocumentTypeNameText', $document->documentType?->name, 1000, 'Не заполнено название типа документа об образовании.');
        } else {
            $fields->requiredText('DocumentNumber', $document->number, 100, 'Не заполнен номер документа об образовании.');
        }

        $fields->requiredDate('DocumentDate', $document->issue_date, 'Не заполнена дата выдачи документа об образовании.');
        $fields->requiredText('DocumentOrganization', $document->document_organization, 500, 'Не заполнена организация, выдавшая документ об образовании.');
        $fields->requiredInt('RegionId', $document->fis_region_id, 'Регион выдачи документа об образовании не сопоставлен со справочником ФИС №8.');
        $fields->optionalInt('EndYear', $document->graduation_year);
        $fields->optionalFloat('GPA', $document->average_score);

        $writer->endElement();
        $writer->endElement();
        $writer->endElement();
    }

    public function applicationUid(AdmissionApplication $application): string
    {
        return $application->uuid ?: 'admission-application-'.$application->getKey();
    }

    private function entrantUid(AdmissionApplication $application, Person $person): string
    {
        return $application->applicant?->uuid ?: 'person-'.$person->getKey();
    }

    /**
     * Для СПО схема требует указать, после 9 или после 11 класса поступает
     * абитуриент. Когда основание не заполнено, элемент не пишем: схема
     * допускает его отсутствие, а выдумывать ступень нельзя.
     */
    private function after11(?string $educationBase): ?bool
    {
        return match ($educationBase) {
            'after_11', 'secondary_general' => true,
            'after_9', 'basic_general' => false,
            default => null,
        };
    }

    /** TPersonalName запрещает цифры, поэтому имя с цифрой лучше не писать вовсе. */
    private function personalName(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && ! preg_match('/\d/', $value) ? $value : null;
    }

    /** Схема ждёт код подразделения строго в виде «ddd-ddd». */
    private function subdivisionCode(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return strlen((string) $digits) === 6 ? substr($digits, 0, 3).'-'.substr($digits, 3, 3) : null;
    }
}
