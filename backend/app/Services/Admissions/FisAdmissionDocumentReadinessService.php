<?php

namespace App\Services\Admissions;

use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;

class FisAdmissionDocumentReadinessService
{
    public function __construct(private readonly FisDictionaryMappingService $mappings)
    {
    }

    /**
     * Возвращает readiness для будущего FIS DTO. XML, SOAP и отправка здесь намеренно отсутствуют.
     *
     * @return array<string, mixed>
     */
    public function assess(AdmissionApplication $application, ?IdentityDocument $identity, ?EducationDocument $education): array
    {
        $missing = [];
        $details = [];

        if (! $identity) {
            $missing[] = 'identity_document';
            $details[] = $this->detail('identity_document_missing', 'identity_document', 'Не указан документ, удостоверяющий личность.');
        } else {
            foreach ([
                'fis_uid' => $identity->fis_uid,
                'number' => $identity->number,
                'issue_date' => $identity->issue_date,
                'release_place' => $identity->release_place,
            ] as $field => $value) {
                if (! filled($value)) {
                    $missing[] = 'identity_document.'.$field;
                    $details[] = $this->detail('identity_document_'.$field.'_missing', 'identity_document.'.$field, 'Не заполнено поле документа личности для будущей выгрузки в ФИС.');
                }
            }

            foreach ([
                ['id' => $identity->document_type_id, 'dictionary' => 'IdentityDocumentTypeID', 'field' => 'identity_document.document_type'],
                ['id' => $identity->release_country_id, 'dictionary' => 'CountryID', 'field' => 'identity_document.release_country'],
            ] as $mapping) {
                $detail = $this->mappings->missingReference($mapping['id'], $mapping['dictionary'], $mapping['field']);
                if ($detail !== null) {
                    $missing[] = $mapping['field'].'.fis_mapping';
                    $details[] = $detail;
                }
            }

            $missing[] = 'person.citizenship.fis_mapping';
            $details[] = $this->detail('person_citizenship_fis_mapping_missing', 'person.citizenship', 'Гражданство Person пока не нормализовано как справочник и не сопоставлено с ФИС.');
        }

        if (! $education) {
            $missing[] = 'education_document';
            $details[] = $this->detail('education_document_missing', 'education_document', 'Не указан документ об образовании.');
        } else {
            foreach ([
                'fis_uid' => $education->fis_uid,
                'number' => $education->number,
                'issue_date' => $education->issue_date,
                'document_organization' => $education->document_organization,
            ] as $field => $value) {
                if (! filled($value)) {
                    $missing[] = 'education_document.'.$field;
                    $details[] = $this->detail('education_document_'.$field.'_missing', 'education_document.'.$field, 'Не заполнено поле документа об образовании для будущей выгрузки в ФИС.');
                }
            }

            foreach ([
                ['id' => $education->document_type_id, 'dictionary' => 'EducationDocumentType', 'field' => 'education_document.document_type'],
                ['id' => $education->country_id, 'dictionary' => 'CountryID', 'field' => 'education_document.country'],
                ['id' => $education->education_level_id, 'dictionary' => 'EducationLevelID', 'field' => 'education_document.education_level'],
            ] as $mapping) {
                $detail = $this->mappings->missingReference($mapping['id'], $mapping['dictionary'], $mapping['field']);
                if ($detail !== null) {
                    $missing[] = $mapping['field'].'.fis_mapping';
                    $details[] = $detail;
                }
            }
        }

        if (! filled($application->applicant?->person?->snils)) {
            $missing[] = 'person.snils';
            $details[] = $this->detail('person_snils_missing', 'person.snils', 'Не заполнен СНИЛС поступающего или причина отсутствия СНИЛС.');
        }

        return [
            'fis_data_ready' => false,
            'fis_mapping_ready' => ! collect($missing)->contains(fn (string $field): bool => str_ends_with($field, '.fis_mapping')),
            'supported_xsd_structures' => [
                'TIdentityDocument',
                'TSchoolCertificateDocument',
                'TSchoolGeneralCertificateDocument',
                'TMiddleEduDiplomaDocument',
                'TBasicDiplomaDocument',
                'TForeignStateEduDocument',
                'TAcademicDiplomaDocument',
                'TEduCustomDocument',
            ],
            'missing_mappings' => array_values(array_unique($missing)),
            'blocking_reasons_detailed' => $details,
            'blocking_reasons' => array_values(array_unique([
                ...$missing,
                'fis_xml_package_generation_is_out_of_scope_for_BACK_005',
            ])),
        ];
    }

    /** @return array{code:string,field:string,message:string} */
    private function detail(string $code, string $field, string $message): array
    {
        return ['code' => $code, 'field' => $field, 'message' => $message];
    }
}
