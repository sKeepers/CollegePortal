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
        private readonly AdmissionApplicationDocumentService $applicationDocuments,
    ) {
    }

    /** @return Collection<int, IdentityDocument> */
    public function listForApplicant(int $applicantId, bool $withArchived = false): Collection
    {
        $applicant = $this->foundationApplicant($applicantId);

        return $this->documents->listForPerson($applicant->person_id, $withArchived);
    }

    /** @return Collection<int, IdentityDocument> */
    public function listForPerson(int $personId, bool $withArchived = false): Collection
    {
        return $this->documents->listForPerson($personId, $withArchived);
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

            return $this->store($applicant->person_id, $applicant->id, $payload, $actor);
        });
    }

    /**
     * Документ человека без заявления в приёмной комиссии: переведённый, восстановленный
     * или заведённый импортом студент. `applicant_id` остаётся пустым намеренно.
     *
     * @param array<string, mixed> $payload
     */
    public function createForPerson(int $personId, array $payload, ?User $actor = null): IdentityDocument
    {
        return DB::transaction(fn (): IdentityDocument => $this->store($personId, null, $payload, $actor));
    }

    /**
     * Реквизиты паспорта из карточки студента и из импорта. Форма и файл заполняют
     * поля, а хранится документ у человека — иначе полнота карточки не сойдётся
     * с приёмной комиссией.
     *
     * @param array<string, mixed> $passport
     */
    public function syncPassportForPerson(int $personId, array $passport, ?User $actor = null): ?IdentityDocument
    {
        $payload = array_filter([
            'series' => $this->trimmed($passport['series'] ?? null),
            'number' => $this->trimmed($passport['number'] ?? null),
            'issue_date' => $this->trimmed($passport['issue_date'] ?? null),
            'issued_by' => $this->trimmed($passport['issued_by'] ?? null),
        ], fn ($value): bool => $value !== null);

        if (! isset($payload['series']) && ! isset($payload['number'])) {
            return null;
        }

        $current = $this->documents->listForPerson($personId)->first();

        if (! $current) {
            return $this->createForPerson($personId, [
                ...$payload,
                'document_type_id' => $this->defaultPassportTypeId(),
                'is_primary' => true,
            ], $actor);
        }

        // Обновляем только то, что действительно изменилось: иначе каждое сохранение
        // карточки плодило бы новую версию документа зарегистрированного заявления.
        $changes = [];
        foreach ($payload as $field => $value) {
            $currentValue = $field === 'issue_date' ? $current->issue_date?->toDateString() : $current->{$field};

            if ((string) $currentValue !== (string) $value) {
                $changes[$field] = $value;
            }
        }

        return $changes === [] ? $current : $this->update($current->id, $changes, $actor);
    }

    private function defaultPassportTypeId(): ?int
    {
        $catalogId = ReferenceCatalog::query()->where('code', 'admission_identity_document_types')->value('id');

        return $catalogId
            ? ReferenceItem::query()->where('catalog_id', $catalogId)->where('code', 'russian_passport')->value('id')
            : null;
    }

    private function trimmed(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** @param array<string, mixed> $payload */
    private function store(int $personId, ?int $applicantId, array $payload, ?User $actor): IdentityDocument
    {
        $this->assertReference($payload['document_type_id'] ?? null, 'admission_identity_document_types', 'document_type_id');
        $this->assertReference($payload['release_country_id'] ?? null, null, 'release_country_id');
        $status = $this->verificationStatus($payload['verification_status'] ?? IdentityDocument::STATUS_RECEIVED);

        if ((bool) ($payload['is_primary'] ?? false)) {
            $this->unsetPrimary($personId);
        }

        $document = $this->documents->create([
            ...$payload,
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $applicantId,
            'person_id' => $personId,
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
    }

    /** @param array<string, mixed> $payload */
    public function update(int $id, array $payload, ?User $actor = null): IdentityDocument
    {
        return DB::transaction(function () use ($id, $payload, $actor): IdentityDocument {
            $document = $this->documents->find($id);
            abort_if(! $document, 404);

            if ($document->replaced_at !== null) {
                throw ValidationException::withMessages(['document' => 'Замененная версия документа недоступна для изменения.']);
            }

            $old = $this->audit->identity($document);
            $this->assertReference($payload['document_type_id'] ?? $document->document_type_id, 'admission_identity_document_types', 'document_type_id');
            $this->assertReference($payload['release_country_id'] ?? $document->release_country_id, null, 'release_country_id');

            if ($this->isMaterialUpdate($payload) && $this->applicationDocuments->isIdentityLinkedToRegisteredApplication($document)) {
                return $this->createNextVersion($document, $payload, $actor, $old);
            }

            if (array_key_exists('is_primary', $payload) && (bool) $payload['is_primary']) {
                $this->unsetPrimary($document->person_id, $document->id);
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
            $document->refresh()->load(['person', 'applicant.person', 'documentType', 'releaseCountry', 'activeFiles']);

            AuditLogService::log('Admissions', 'identity_document_updated', $document, $old, $this->audit->identity($document), user: $actor);

            return $document;
        });
    }

    public function archive(int $id, ?User $actor = null): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $document = $this->documents->find($id);
            abort_if(! $document, 404);

            if ($this->applicationDocuments->isIdentityLinkedToRegisteredApplication($document)) {
                throw ValidationException::withMessages(['document' => 'Документ закреплен за зарегистрированным заявлением и не может быть архивирован.']);
            }

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

    private function unsetPrimary(int $personId, ?int $exceptId = null): void
    {
        IdentityDocument::query()
            ->active()
            ->where('person_id', $personId)
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
            'issued_by',
            'subdivision_code',
            'release_country_id',
            'release_country_name',
            'release_place',
            'valid_until',
            'fis_uid',
            'fis_identity_document_type_id',
            'fis_nationality_type_id',
            'fis_release_country_id',
            'metadata',
        ])->isNotEmpty();
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $old */
    private function createNextVersion(IdentityDocument $document, array $payload, ?User $actor, array $old): IdentityDocument
    {
        $base = collect($document->getAttributes())->only([
            'applicant_id',
            'person_id',
            'document_type_id',
            'series',
            'number',
            'issue_date',
            'issued_by',
            'subdivision_code',
            'release_country_id',
            'release_country_name',
            'release_place',
            'valid_until',
            'is_primary',
            'fis_uid',
            'fis_identity_document_type_id',
            'fis_nationality_type_id',
            'fis_release_country_id',
            'metadata',
        ])->all();

        $status = $this->verificationStatus($payload['verification_status'] ?? IdentityDocument::STATUS_PENDING_REVIEW);
        if ((bool) ($payload['is_primary'] ?? $document->is_primary)) {
            $this->unsetPrimary($document->person_id, $document->id);
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
            'verified_by' => $status === IdentityDocument::STATUS_VERIFIED ? $actor?->id : null,
            'verified_at' => $status === IdentityDocument::STATUS_VERIFIED ? now() : null,
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

        AuditLogService::log('Admissions', 'identity_document_version_created', $next, $old, $this->audit->identity($next), user: $actor);

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
