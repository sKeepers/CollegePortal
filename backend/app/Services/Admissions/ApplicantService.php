<?php

namespace App\Services\Admissions;

use App\Models\Admissions\Applicant;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\User;
use App\Repositories\Admissions\ApplicantRepository;
use App\Repositories\Admissions\PersonRepository;
use App\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Application service foundation-профилей абитуриентов.
 */
class ApplicantService
{
    public function __construct(
        private readonly ApplicantRepository $applicants,
        private readonly PersonRepository $people,
        private readonly SnilsService $snils,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<Applicant>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->applicants->paginate($filters);
    }

    public function find(int $id): ?Applicant
    {
        return $this->applicants->find($id);
    }

    /**
     * Создает foundation-профиль абитуриента без дублирования Person.
     *
     * @param array<string, mixed> $personData
     * @param array<string, mixed> $applicantData
     */
    public function createFoundation(array $personData, array $applicantData = [], ?User $actor = null): Applicant
    {
        return DB::transaction(function () use ($personData, $applicantData, $actor): Applicant {
            $person = $this->resolvePerson($personData);
            $existingApplicant = $this->applicants->findByPersonId($person->id);

            if ($existingApplicant !== null) {
                AuditLogService::log('Admissions', 'applicant_existing_reused', $existingApplicant, null, [
                    'person_id' => $person->id,
                    'applicant_id' => $existingApplicant->id,
                ], user: $actor);

                return $existingApplicant;
            }

            $applicant = $this->applicants->create([
                'uuid' => (string) Str::uuid(),
                'person_id' => $person->id,
                'source_id' => $applicantData['source_id'] ?? $this->referenceItemId('admission_sources', $applicantData['source_code'] ?? 'manual'),
                'status_id' => $applicantData['status_id'] ?? $this->referenceItemId('applicant_statuses', $applicantData['status_code'] ?? 'active'),
                'first_contact_at' => $applicantData['first_contact_at'] ?? now(),
                'responsible_user_id' => $applicantData['responsible_user_id'] ?? $actor?->id,
                'notes' => $applicantData['notes'] ?? null,
                'archived_at' => null,
            ]);

            AuditLogService::log('Admissions', 'applicant_created', $applicant, null, [
                'person_id' => $person->id,
                'source_id' => $applicant->source_id,
                'status_id' => $applicant->status_id,
                'responsible_user_id' => $applicant->responsible_user_id,
            ], user: $actor);

            return $applicant;
        });
    }

    /**
     * Изменяет служебные поля foundation-профиля абитуриента.
     *
     * @param array<string, mixed> $payload
     */
    public function updateFoundation(Applicant $applicant, array $payload, ?User $actor = null): Applicant
    {
        return DB::transaction(function () use ($applicant, $payload, $actor): Applicant {
            if ($applicant->archived_at !== null) {
                throw ValidationException::withMessages(['applicant' => 'Архивный профиль абитуриента недоступен для изменения.']);
            }

            $old = $this->auditPayload($applicant);
            $updated = $this->applicants->update($applicant, collect($payload)->only([
                'source_id',
                'status_id',
                'first_contact_at',
                'responsible_user_id',
                'notes',
            ])->all());

            AuditLogService::log('Admissions', 'applicant_updated', $updated, $old, $this->auditPayload($updated), user: $actor);

            return $updated;
        });
    }

    public function archiveFoundation(Applicant $applicant, ?User $actor = null): Applicant
    {
        return DB::transaction(function () use ($applicant, $actor): Applicant {
            if ($applicant->archived_at !== null) {
                return $applicant->load(['person', 'status', 'source', 'responsibleUser']);
            }

            $old = $this->auditPayload($applicant);
            $updated = $this->applicants->update($applicant, [
                'archived_at' => now(),
            ]);

            AuditLogService::log('Admissions', 'applicant_archived', $updated, $old, $this->auditPayload($updated), user: $actor);

            return $updated;
        });
    }

    /**
     * @param array<string, mixed> $personData
     */
    private function resolvePerson(array $personData): Person
    {
        if (! empty($personData['person_id'])) {
            $person = $this->people->find((int) $personData['person_id']);

            if ($person === null) {
                throw ValidationException::withMessages(['person_id' => 'Выбранная личная карточка не найдена.']);
            }

            if (blank($person->snils)) {
                throw ValidationException::withMessages(['person_id' => 'Для абитуриента требуется личная карточка с СНИЛС.']);
            }

            return $this->ensurePersonUuid($person);
        }

        $normalizedSnils = $this->snils->normalize($personData['snils'] ?? null);
        $hash = $this->snils->hash($normalizedSnils);
        $bySnils = Person::query()->where('snils_hash', $hash)->first();
        if ($bySnils) {
            return $this->ensurePersonUuid($bySnils);
        }

        $personData['snils'] = $normalizedSnils;
        $personData['snils_hash'] = $hash;
        $matches = $this->people->findPossibleMatches($personData);

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'person_id' => 'Найдено несколько возможных Person. Выберите существующую запись вручную.',
                'duplicate_person_ids' => $matches->pluck('id')->map(fn (int $id): string => (string) $id)->values()->all(),
            ]);
        }

        if ($matches->count() === 1) {
            return $this->ensurePersonUuid($matches->first());
        }

        return $this->people->create([
            ...$personData,
            'uuid' => (string) Str::uuid(),
            'status' => $personData['status'] ?? 'active',
        ]);
    }

    private function ensurePersonUuid(Person $person): Person
    {
        if (empty($person->uuid)) {
            $person->forceFill(['uuid' => (string) Str::uuid()])->save();
        }

        return $person;
    }

    private function referenceItemId(string $catalogCode, string $itemCode): int
    {
        $catalogId = ReferenceCatalog::query()->where('code', $catalogCode)->value('id');
        $itemId = $catalogId
            ? ReferenceItem::query()->where('catalog_id', $catalogId)->where('code', $itemCode)->value('id')
            : null;

        if (! $itemId) {
            throw ValidationException::withMessages([
                $catalogCode => "Не найден элемент справочника {$catalogCode}.{$itemCode}.",
            ]);
        }

        return (int) $itemId;
    }

    /** @return array<string, mixed> */
    private function auditPayload(Applicant $applicant): array
    {
        return [
            'id' => $applicant->id,
            'person_id' => $applicant->person_id,
            'source_id' => $applicant->source_id,
            'status_id' => $applicant->status_id,
            'first_contact_at' => $applicant->first_contact_at?->toISOString(),
            'responsible_user_id' => $applicant->responsible_user_id,
            'archived_at' => $applicant->archived_at?->toISOString(),
        ];
    }
}
