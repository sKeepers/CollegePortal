<?php

namespace App\Services\Admissions;

use App\Models\Admissions\Applicant;
use App\Models\Admissions\EducationDocument;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\User;
use App\Repositories\Admissions\EducationDocumentRepository;
use App\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EducationDocumentService
{
    public function __construct(
        private readonly EducationDocumentRepository $documents,
        private readonly AdmissionDocumentAudit $audit,
        private readonly AdmissionApplicationDocumentService $applicationDocuments,
    ) {
    }

    /** @return Collection<int, EducationDocument> */
    public function listForApplicant(int $applicantId, bool $withArchived = false): Collection
    {
        $this->foundationApplicant($applicantId);

        return $this->documents->listForApplicant($applicantId, $withArchived);
    }

    public function find(int $id): ?EducationDocument
    {
        return $this->documents->find($id);
    }

    /** @param array<string, mixed> $payload */
    public function create(int $applicantId, array $payload, ?User $actor = null): EducationDocument
    {
        return DB::transaction(function () use ($applicantId, $payload, $actor): EducationDocument {
            $applicant = $this->foundationApplicant($applicantId);
            $this->assertReference($payload['document_type_id'] ?? null, 'admission_education_document_types', 'document_type_id');
            $this->assertReference($payload['country_id'] ?? null, null, 'country_id');
            $this->assertReference($payload['education_level_id'] ?? null, 'education_levels', 'education_level_id');
            $status = $this->verificationStatus($payload['verification_status'] ?? EducationDocument::STATUS_RECEIVED);

            if ((bool) ($payload['is_primary'] ?? false)) {
                $this->unsetPrimary($applicant->id);
            }

            $document = $this->documents->create([
                ...$payload,
                'uuid' => (string) Str::uuid(),
                'applicant_id' => $applicant->id,
                'number_hash' => $this->documentHash($payload['series'] ?? null, $payload['number'] ?? null),
                'verification_status' => $status,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
                'verified_by' => $status === EducationDocument::STATUS_VERIFIED ? $actor?->id : null,
                'verified_at' => $status === EducationDocument::STATUS_VERIFIED ? now() : null,
                'archived_at' => null,
            ]);

            AuditLogService::log('Admissions', 'education_document_created', $document, null, $this->audit->education($document), user: $actor);

            return $document;
        });
    }

    /** @param array<string, mixed> $payload */
    public function update(int $id, array $payload, ?User $actor = null): EducationDocument
    {
        return DB::transaction(function () use ($id, $payload, $actor): EducationDocument {
            $document = $this->documents->find($id);
            abort_if(! $document, 404);

            if ($document->replaced_at !== null) {
                throw ValidationException::withMessages(['document' => 'Замененная версия документа недоступна для изменения.']);
            }

            $old = $this->audit->education($document);
            $this->assertReference($payload['document_type_id'] ?? $document->document_type_id, 'admission_education_document_types', 'document_type_id');
            $this->assertReference($payload['country_id'] ?? $document->country_id, null, 'country_id');
            $this->assertReference($payload['education_level_id'] ?? $document->education_level_id, 'education_levels', 'education_level_id');

            if ($this->isMaterialUpdate($payload) && $this->applicationDocuments->isEducationLinkedToRegisteredApplication($document)) {
                return $this->createNextVersion($document, $payload, $actor, $old);
            }

            if (array_key_exists('is_primary', $payload) && (bool) $payload['is_primary']) {
                $this->unsetPrimary($document->applicant_id, $document->id);
            }

            if (array_key_exists('verification_status', $payload)) {
                $payload['verification_status'] = $this->verificationStatus($payload['verification_status']);
                if ($payload['verification_status'] === EducationDocument::STATUS_VERIFIED) {
                    $payload['verified_by'] = $actor?->id;
                    $payload['verified_at'] = now();
                }
            }

            if (array_key_exists('series', $payload) || array_key_exists('number', $payload)) {
                $payload['number_hash'] = $this->documentHash($payload['series'] ?? $document->series, $payload['number'] ?? $document->number);
            }

            $document->update([...$payload, 'updated_by' => $actor?->id]);
            $document->refresh()->load(['applicant.person', 'documentType', 'country', 'educationLevel', 'activeFiles']);

            AuditLogService::log('Admissions', 'education_document_updated', $document, $old, $this->audit->education($document), user: $actor);

            return $document;
        });
    }

    public function archive(int $id, ?User $actor = null): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $document = $this->documents->find($id);
            abort_if(! $document, 404);

            if ($this->applicationDocuments->isEducationLinkedToRegisteredApplication($document)) {
                throw ValidationException::withMessages(['document' => 'Документ закреплен за зарегистрированным заявлением и не может быть архивирован.']);
            }

            $old = $this->audit->education($document);
            $document->update([
                'verification_status' => EducationDocument::STATUS_ARCHIVED,
                'archived_by' => $actor?->id,
                'archived_at' => now(),
                'updated_by' => $actor?->id,
            ]);

            foreach ($document->activeFiles as $file) {
                $file->update(['archived_by' => $actor?->id, 'archived_at' => now()]);
            }

            AuditLogService::log('Admissions', 'education_document_archived', $document, $old, $this->audit->education($document), user: $actor);
        });
    }

    private function foundationApplicant(int $applicantId): Applicant
    {
        $applicant = Applicant::query()->with('person')->active()->find($applicantId);

        if (! $applicant || ! $applicant->person) {
            abort(404, 'Foundation-абитуриент не найден.');
        }

        return $applicant;
    }

    private function verificationStatus(string $status): string
    {
        if (! in_array($status, [
            EducationDocument::STATUS_RECEIVED,
            EducationDocument::STATUS_PENDING_REVIEW,
            EducationDocument::STATUS_VERIFIED,
            EducationDocument::STATUS_REJECTED,
            EducationDocument::STATUS_REPLACEMENT_REQUIRED,
        ], true)) {
            throw ValidationException::withMessages(['verification_status' => 'Недопустимый статус проверки документа.']);
        }

        return $status;
    }

    private function unsetPrimary(int $applicantId, ?int $exceptId = null): void
    {
        EducationDocument::query()
            ->active()
            ->where('applicant_id', $applicantId)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_primary' => false]);
    }

    /** @param array<string, mixed> $payload */
    private function isMaterialUpdate(array $payload): bool
    {
        return collect($payload)->keys()->intersect([
            'document_type_id',
            'series',
            'number',
            'issue_date',
            'document_organization',
            'country_id',
            'country_name',
            'education_level_id',
            'graduation_year',
            'is_original',
            'original_received_at',
            'average_score',
            'average_score_scale',
            'has_attachment',
            'qualification_name',
            'speciality_name',
            'registration_number',
            'is_nostrificated',
            'fis_uid',
            'fis_document_type_id',
            'fis_country_id',
            'fis_region_id',
            'metadata',
        ])->isNotEmpty();
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $old */
    private function createNextVersion(EducationDocument $document, array $payload, ?User $actor, array $old): EducationDocument
    {
        $base = collect($document->getAttributes())->only([
            'applicant_id',
            'document_type_id',
            'series',
            'number',
            'issue_date',
            'document_organization',
            'country_id',
            'country_name',
            'education_level_id',
            'graduation_year',
            'is_original',
            'original_received_at',
            'average_score',
            'average_score_scale',
            'has_attachment',
            'qualification_name',
            'speciality_name',
            'registration_number',
            'is_nostrificated',
            'is_primary',
            'fis_uid',
            'fis_document_type_id',
            'fis_country_id',
            'fis_region_id',
            'metadata',
        ])->all();

        $status = $this->verificationStatus($payload['verification_status'] ?? EducationDocument::STATUS_PENDING_REVIEW);
        if ((bool) ($payload['is_primary'] ?? $document->is_primary)) {
            $this->unsetPrimary($document->applicant_id, $document->id);
        }

        $next = $this->documents->create([
            ...$base,
            ...$payload,
            'uuid' => (string) Str::uuid(),
            'previous_version_id' => $document->id,
            'version_number' => ((int) $document->version_number) + 1,
            'number_hash' => $this->documentHash($payload['series'] ?? $document->series, $payload['number'] ?? $document->number),
            'verification_status' => $status,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
            'verified_by' => $status === EducationDocument::STATUS_VERIFIED ? $actor?->id : null,
            'verified_at' => $status === EducationDocument::STATUS_VERIFIED ? now() : null,
            'archived_at' => null,
            'replaced_at' => null,
        ]);

        $document->update([
            'is_primary' => false,
            'replaced_by_document_id' => $next->id,
            'replaced_by' => $actor?->id,
            'replaced_at' => now(),
            'updated_by' => $actor?->id,
        ]);

        AuditLogService::log('Admissions', 'education_document_version_created', $next, $old, $this->audit->education($next), user: $actor);

        return $next;
    }

    private function documentHash(?string $series, ?string $number): ?string
    {
        $value = trim((string) $series).'|'.trim((string) $number);

        return trim(str_replace('|', '', $value)) === '' ? null : hash('sha256', mb_strtolower($value));
    }

    private function assertReference(mixed $id, ?string $catalogCode, string $field): void
    {
        if ($id === null || $id === '') {
            return;
        }

        $query = ReferenceItem::query()->whereKey((int) $id)->where('is_active', true);
        if ($catalogCode !== null) {
            $catalogId = ReferenceCatalog::query()->where('code', $catalogCode)->value('id');
            $query->where('catalog_id', $catalogId);
        }

        if (! $query->exists()) {
            throw ValidationException::withMessages([$field => 'Выбранный элемент справочника недоступен.']);
        }
    }
}
