<?php

namespace App\Services\Admissions;

use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;
use App\Models\Person;
use App\Models\Student;
use App\Repositories\Admissions\AdmissionApplicationRepository;
use Illuminate\Validation\ValidationException;

/**
 * Единственный расчёт комплектности документов. Приёмная комиссия считает готовность
 * заявления, контингент — полноту карточки студента; правила состава живут здесь,
 * второй реализации быть не должно.
 */
class AdmissionDocumentReadinessService
{
    public function __construct(
        private readonly AdmissionApplicationRepository $applications,
        private readonly AdmissionApplicationDocumentService $applicationDocuments,
        private readonly PersonDocumentService $personDocuments,
        private readonly FisAdmissionDocumentReadinessService $fisReadiness,
        private readonly DocumentMaskingService $masking,
    ) {
    }

    /** @return array<string, mixed> */
    public function forApplication(int $applicationId): array
    {
        $application = $this->applications->find($applicationId);
        abort_if(! $application, 404);
        $application->loadMissing('applicant.person');

        $documents = $this->applicationDocuments->documentsForReadiness($application);
        $identity = $documents['identity'];
        $education = $documents['education'];
        $documentSet = $documents['set'];
        $person = $application->applicant?->person;
        $identityFiles = $identity?->activeFiles()->count() ?? 0;
        $educationFiles = $education?->activeFiles()->count() ?? 0;

        $blocking = [];
        $blockingDetailed = [];
        if (! $identity) {
            $blocking[] = 'Нет действующего документа, удостоверяющего личность.';
            $blockingDetailed[] = $this->reason('identity_document_missing', 'identity_document', 'Нет действующего документа, удостоверяющего личность.');
        }
        if (! filled($person?->snils)) {
            $blocking[] = 'Не заполнен СНИЛС Person.';
            $blockingDetailed[] = $this->reason('person_snils_missing', 'person.snils', 'Не заполнен СНИЛС Person.');
        }
        if (! $education) {
            $blocking[] = 'Нет действующего документа об образовании.';
            $blockingDetailed[] = $this->reason('education_document_missing', 'education_document', 'Нет действующего документа об образовании.');
        }
        if ($identity && $identityFiles === 0) {
            $blocking[] = 'Нет файла-образа документа личности.';
            $blockingDetailed[] = $this->reason('identity_document_file_missing', 'identity_document.files', 'Нет файла-образа документа личности.');
        }
        if ($education && $educationFiles === 0) {
            $blocking[] = 'Нет файла-образа документа об образовании.';
            $blockingDetailed[] = $this->reason('education_document_file_missing', 'education_document.files', 'Нет файла-образа документа об образовании.');
        }

        $reviewBlocking = [];
        $reviewBlockingDetailed = [];
        if ($identity && $identity->verification_status !== IdentityDocument::STATUS_VERIFIED) {
            $reviewBlocking[] = 'Документ личности не проверен.';
            $reviewBlockingDetailed[] = $this->reason('identity_document_not_verified', 'identity_document.verification_status', 'Документ личности не проверен.');
        }
        if ($education && $education->verification_status !== EducationDocument::STATUS_VERIFIED) {
            $reviewBlocking[] = 'Документ об образовании не проверен.';
            $reviewBlockingDetailed[] = $this->reason('education_document_not_verified', 'education_document.verification_status', 'Документ об образовании не проверен.');
        }

        $fis = $this->fisReadiness->assess($application, $identity, $education);

        return [
            'application_id' => $application->id,
            'applicant_id' => $application->applicant_id,
            'document_set_id' => $documentSet->id,
            'linked_identity_document_id' => $documentSet->identity_document_id,
            'linked_education_document_id' => $documentSet->education_document_id,
            'identity_document' => $this->component($identity !== null, $identity?->verification_status, $identityFiles),
            'snils' => [
                'status' => filled($person?->snils) ? 'complete' : 'missing',
                'masked' => $this->masking->snils($person?->snils),
                'required' => true,
            ],
            'education_document' => $this->component($education !== null, $education?->verification_status, $educationFiles),
            'files' => [
                'identity_files_count' => $identityFiles,
                'education_files_count' => $educationFiles,
                'status' => ($identityFiles > 0 && $educationFiles > 0) ? 'complete' : 'incomplete',
            ],
            'internal_complete' => $blocking === [],
            'review_complete' => $blocking === [] && $reviewBlocking === [],
            'fis_data_ready' => $fis['fis_data_ready'],
            'blocking_reasons' => array_values(array_unique($blocking)),
            'blocking_reasons_detailed' => $blockingDetailed,
            'review_blocking_reasons' => array_values(array_unique($reviewBlocking)),
            'review_blocking_reasons_detailed' => $reviewBlockingDetailed,
            'fis' => $fis,
        ];
    }

    /**
     * Полнота карточки студента: паспорт, документ об образовании и СНИЛС.
     * Неполнота не мешает сохранить студента — она мешает операциям, где документ
     * нужен по закону: выгрузке в ФИС ГИА и ФРДО, приказу о зачислении и диплому.
     *
     * @return array<string, mixed>
     */
    public function forStudent(Student $student): array
    {
        return $this->forStudents([$student])[$student->id];
    }

    /**
     * Пакетный расчёт для реестра: три запроса на страницу вместо запроса на строку.
     *
     * @param iterable<Student> $students
     * @return array<int, array<string, mixed>> ключ — student_id
     */
    public function forStudents(iterable $students): array
    {
        $students = collect($students);
        $personIds = $students->pluck('person_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();

        $people = $personIds === [] ? collect() : Person::query()->whereKey($personIds)->get()->keyBy('id');
        $identities = $this->personDocuments->currentIdentityForPeople($personIds);
        $educations = $this->personDocuments->currentEducationForPeople($personIds);

        return $students->mapWithKeys(function (Student $student) use ($people, $identities, $educations): array {
            $personId = $student->person_id !== null ? (int) $student->person_id : null;
            $person = $personId !== null ? $people->get($personId) : null;

            return [$student->id => $this->assessCard(
                $student,
                $person,
                $personId !== null ? $identities->get($personId) : null,
                $personId !== null ? $educations->get($personId) : null,
            )];
        })->all();
    }

    /**
     * Сводка по контингенту для напоминания «Учебной части».
     *
     * @return array<string, int>
     */
    public function studentCardSummary(): array
    {
        $summary = ['total' => 0, 'incomplete' => 0, 'missing_identity' => 0, 'missing_education' => 0, 'missing_snils' => 0];

        $this->eachStudentCard(function (array $card) use (&$summary): void {
            $summary['total']++;

            if (! $card['complete']) {
                $summary['incomplete']++;
            }
            if ($card['identity_document']['status'] === 'missing') {
                $summary['missing_identity']++;
            }
            if ($card['education_document']['status'] === 'missing') {
                $summary['missing_education']++;
            }
            if ($card['snils']['status'] === 'missing') {
                $summary['missing_snils']++;
            }
        });

        return $summary;
    }

    /**
     * Идентификаторы студентов с неполной карточкой — для фильтра в реестре.
     * Считаются тем же расчётом, что и карточка, чтобы список и признак не разошлись.
     *
     * @return list<int>
     */
    public function incompleteStudentIds(): array
    {
        $ids = [];

        $this->eachStudentCard(function (array $card) use (&$ids): void {
            if (! $card['complete']) {
                $ids[] = (int) $card['student_id'];
            }
        });

        return $ids;
    }

    /**
     * Жёсткая блокировка операции, для которой документ нужен по закону.
     *
     * @throws ValidationException
     */
    public function assertStudentCardComplete(Student $student, string $operation, string $field = 'student_id'): void
    {
        $card = $this->forStudent($student);

        if ($card['complete']) {
            return;
        }

        throw ValidationException::withMessages([
            $field => array_merge(
                [$operation.': карточка студента неполна.'],
                $card['blocking_reasons'],
            ),
        ]);
    }

    /** Обходит контингент постранично и отдаёт расчёт по каждому студенту. */
    private function eachStudentCard(callable $callback): void
    {
        Student::query()
            ->whereNull('archived_at')
            ->whereNotIn('status', ['graduated', 'expelled'])
            ->orderBy('id')
            ->chunkById(500, function ($students) use ($callback): void {
                foreach ($this->forStudents($students) as $card) {
                    $callback($card);
                }
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function assessCard(Student $student, ?Person $person, ?IdentityDocument $identity, ?EducationDocument $education): array
    {
        // Легаси-карточки заводились без Person, и СНИЛС у них лежит в строке студента.
        $snils = filled($person?->snils) ? $person->snils : $student->snils;

        $blocking = [];
        $blockingDetailed = [];

        if (! $person) {
            $blocking[] = 'Карточка не связана с человеком, документы прикрепить нельзя.';
            $blockingDetailed[] = $this->reason('person_missing', 'person_id', 'Карточка не связана с человеком, документы прикрепить нельзя.');
        }
        if (! $identity) {
            $blocking[] = 'Нет документа, удостоверяющего личность.';
            $blockingDetailed[] = $this->reason('identity_document_missing', 'identity_document', 'Нет документа, удостоверяющего личность.');
        }
        if (! $education) {
            $blocking[] = 'Нет документа об образовании.';
            $blockingDetailed[] = $this->reason('education_document_missing', 'education_document', 'Нет документа об образовании.');
        }
        if (! filled($snils)) {
            $blocking[] = 'Не заполнен СНИЛС.';
            $blockingDetailed[] = $this->reason('person_snils_missing', 'snils', 'Не заполнен СНИЛС.');
        }

        return [
            'student_id' => $student->id,
            'person_id' => $student->person_id,
            'complete' => $blocking === [],
            'identity_document' => $this->component($identity !== null, $identity?->verification_status, $this->filesCount($identity)),
            'education_document' => $this->component($education !== null, $education?->verification_status, $this->filesCount($education)),
            'snils' => [
                'status' => filled($snils) ? 'complete' : 'missing',
                'masked' => $this->masking->snils($snils),
                'required' => true,
            ],
            'missing' => array_values(array_map(fn (array $reason): string => $reason['code'], $blockingDetailed)),
            'blocking_reasons' => array_values(array_unique($blocking)),
            'blocking_reasons_detailed' => $blockingDetailed,
        ];
    }

    private function filesCount(?object $document): int
    {
        if ($document === null) {
            return 0;
        }

        return $document->relationLoaded('activeFiles')
            ? $document->activeFiles->count()
            : $document->activeFiles()->count();
    }

    /** @return array<string, mixed> */
    private function component(bool $exists, ?string $verificationStatus, int $filesCount): array
    {
        return [
            'status' => $exists ? 'present' : 'missing',
            'verification_status' => $verificationStatus,
            'files_count' => $filesCount,
        ];
    }

    /** @return array{code:string,field:string,message:string} */
    private function reason(string $code, string $field, string $message): array
    {
        return ['code' => $code, 'field' => $field, 'message' => $message];
    }
}
