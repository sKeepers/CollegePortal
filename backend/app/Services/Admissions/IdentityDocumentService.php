<?php

namespace App\Services\Admissions;

use App\Models\Admissions\Applicant;
use App\Models\Admissions\IdentityDocument;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\User;
use App\Repositories\Admissions\IdentityDocumentRepository;
use App\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IdentityDocumentService
{
    public function __construct(
        private readonly IdentityDocumentRepository $documents,
        private readonly AdmissionDocumentAudit $audit,
    ) {
    }

    /** @return Collection<int, IdentityDocument> */
    public function listForApplicant(int $applicantId, bool $withArchived = false): Collection
    {
        $this->foundationApplicant($applicantId);

        return $this->documents->listForApplicant($applicantId, $withArchived);
    }

    public function find(int $id): ?IdentityDocument
    {
        return $this->documents->find($id);
    }

    /** @param array<string, mixed> $payload */
    public function create(int $applicantId, array $payload, ?User $actor = null): IdentityDocument
    {
        return DB::transaction(function () use ($applicantId, $payload, $actor): IdentityDocument {
            $applicant = $this->foundationApplicant($applicantId);
            $this->assertReference($payload['document_type_id'] ?? null, 'admission_identity_document_types', 'document_type_id');
            $this->assertReference($payload['release_country_id'] ?? null, null, 'release_country_id');
            $status = $this->verificationStatus($payload['verification_status'] ?? IdentityDocument::STATUS_RECEIVED);

            if ((bool) ($payload['is_primary'] ?? false)) {
                $this->unsetPrimary($applicant->id);
            }

            $document = $this->documents->create([
                ...$payload,
                'uuid' => (string) Str::uuid(),
                'applicant_id' => $applicant->id,
                'person_id' => $applicant->person_id,
                'number_hash' => $this->documentHash($payload['series'] ?? null, $payload['number'] ?? null),
                'verification_status' => $status,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
                'verified_by' => $status === IdentityDocument::STATUS_VERIFIED ? $actor?->id : null,
                'verified_at' => $status === IdentityDocument::STATUS_VERIFIED ? now() : null,
                'archived_at' => null,
            ]);

            AuditLogService::log('Admissions', 'identity_document_created', $document, null, $this->audit->identity($document), user: $actor);

            return $document;
        });
    }

    /** @param array<string, mixed> $payload */
    public function update(int $id, array $payload, ?User $actor = null): IdentityDocument
    {
        return DB::transaction(function () use ($id, $payload, $actor): IdentityDocument {
            $document = $this->documents->find($id);
            abort_if(! $document, 404);

            $old = $this->audit->identity($document);
            $this->assertReference($payload['document_type_id'] ?? $document->document_type_id, 'admission_identity_document_types', 'document_type_id');
            $this->assertReference($payload['release_country_id'] ?? $document->release_country_id, null, 'release_country_id');

            if (array_key_exists('is_primary', $payload) && (bool) $payload['is_primary']) {
                $this->unsetPrimary($document->applicant_id, $document->id);
            }

            if (array_key_exists('verification_status', $payload)) {
                $payload['verification_status'] = $this->verificationStatus($payload['verification_status']);
                if ($payload['verification_status'] === IdentityDocument::STATUS_VERIFIED) {
                    $payload['verified_by'] = $actor?->id;
                    $payload['verified_at'] = now();
                }
            }

            if (array_key_exists('series', $payload) || array_key_exists('number', $payload)) {
                $payload['number_hash'] = $this->documentHash($payload['series'] ?? $document->series, $payload['number'] ?? $document->number);
            }

            $document->update([...$payload, 'updated_by' => $actor?->id]);
            $document->refresh()->load(['applicant.person', 'documentType', 'releaseCountry', 'activeFiles']);

            AuditLogService::log('Admissions', 'identity_document_updated', $document, $old, $this->audit->identity($document), user: $actor);

            return $document;
        });
    }

    public function archive(int $id, ?User $actor = null): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $document = $this->documents->find($id);
            abort_if(! $document, 404);

            $old = $this->audit->identity($document);
            $document->update([
                'verification_status' => IdentityDocument::STATUS_ARCHIVED,
                'archived_by' => $actor?->id,
                'archived_at' => now(),
                'updated_by' => $actor?->id,
            ]);

            foreach ($document->activeFiles as $file) {
                $file->update(['archived_by' => $actor?->id, 'archived_at' => now()]);
            }

            AuditLogService::log('Admissions', 'identity_document_archived', $document, $old, $this->audit->identity($document), user: $actor);
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
            IdentityDocument::STATUS_RECEIVED,
            IdentityDocument::STATUS_PENDING_REVIEW,
            IdentityDocument::STATUS_VERIFIED,
            IdentityDocument::STATUS_REJECTED,
            IdentityDocument::STATUS_REPLACEMENT_REQUIRED,
        ], true)) {
            throw ValidationException::withMessages(['verification_status' => 'Недопустимый статус проверки документа.']);
        }

        return $status;
    }

    private function unsetPrimary(int $applicantId, ?int $exceptId = null): void
    {
        IdentityDocument::query()
            ->active()
            ->where('applicant_id', $applicantId)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_primary' => false]);
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
