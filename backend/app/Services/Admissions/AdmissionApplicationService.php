<?php

namespace App\Services\Admissions;

use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\Applicant;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\User;
use App\Repositories\Admissions\AdmissionApplicationRepository;
use App\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Application service жизненного цикла foundation-заявления.
 */
class AdmissionApplicationService
{
    public function __construct(
        private readonly AdmissionApplicationRepository $applications,
        private readonly AdmissionDocumentReadinessService $documentReadiness,
        private readonly AdmissionApplicationDocumentService $applicationDocuments,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<AdmissionApplication>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->applications->paginate($filters);
    }

    public function find(int $id): ?AdmissionApplication
    {
        return $this->applications->find($id);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createDraft(array $payload, ?User $actor = null): AdmissionApplication
    {
        return DB::transaction(function () use ($payload, $actor): AdmissionApplication {
            $applicant = Applicant::query()->with('person')->find((int) $payload['applicant_id']);

            if (! $applicant || ! $applicant->person) {
                throw ValidationException::withMessages(['applicant_id' => 'Абитуриент или связанная Person не найдены.']);
            }

            $admissionYear = (int) $payload['admission_year'];
            $applicationNumber = $this->blankToNull($payload['application_number'] ?? null);
            $this->assertApplicationNumberUnique($admissionYear, $applicationNumber);

            $application = $this->applications->create([
                'uuid' => (string) Str::uuid(),
                'record_type' => AdmissionApplication::RECORD_TYPE_FOUNDATION,
                'foundation_version' => 1,
                'applicant_id' => $applicant->id,
                'person_id' => $applicant->person_id,
                'admission_year' => $admissionYear,
                'application_number' => $applicationNumber,
                'education_program_id' => (int) $payload['education_program_id'],
                ...$this->legacyPersonSnapshot($applicant->person),
                'education_base' => $this->legacyEducationBase($payload['education_base'] ?? null),
                'status' => AdmissionApplication::STATUS_DRAFT,
                'status_id' => $this->referenceItemId('admission_application_statuses', AdmissionApplication::STATUS_DRAFT),
                'source_id' => $payload['source_id'] ?? $this->referenceItemId('admission_sources', 'manual'),
                'submitted_at' => $payload['submitted_at'] ?? today()->toDateString(),
                'registered_at' => null,
                'comment' => $payload['comment'] ?? null,
                'metadata' => $payload['metadata'] ?? null,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
                'archived_at' => null,
            ]);

            AuditLogService::log('Admissions', 'admission_application_created', $application, null, $this->safeSnapshot($application), user: $actor);

            return $application;
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateDraft(AdmissionApplication $application, array $payload, ?User $actor = null): AdmissionApplication
    {
        return DB::transaction(function () use ($application, $payload, $actor): AdmissionApplication {
            $application = $this->freshFoundation($application);

            if (! $application->isDraft()) {
                AuditLogService::log('Admissions', 'admission_application_update_rejected', $application, $this->safeSnapshot($application), [
                    'reason' => 'status_not_draft',
                    'status' => $application->statusCode(),
                ], user: $actor);

                throw ValidationException::withMessages(['status' => 'Редактировать можно только черновик заявления.']);
            }

            $old = $this->safeSnapshot($application);
            $allowed = collect($payload)->only([
                'admission_year',
                'application_number',
                'education_program_id',
                'source_id',
                'submitted_at',
                'education_base',
                'comment',
                'metadata',
            ])->all();

            if (array_key_exists('education_base', $allowed)) {
                $allowed['education_base'] = $this->legacyEducationBase($allowed['education_base']);
            }

            $admissionYear = (int) ($allowed['admission_year'] ?? $application->admission_year);
            $applicationNumber = $this->blankToNull($allowed['application_number'] ?? $application->application_number);
            $this->assertApplicationNumberUnique($admissionYear, $applicationNumber, $application->id);

            $allowed['application_number'] = $applicationNumber;
            $allowed['updated_by'] = $actor?->id;

            $application->update($allowed);
            $application->refresh()->load(['applicant.person', 'statusItem', 'source', 'educationProgram.specialty']);

            AuditLogService::log('Admissions', 'admission_application_updated', $application, $old, $this->safeSnapshot($application), user: $actor);

            return $application;
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function register(AdmissionApplication $application, array $payload = [], ?User $actor = null): AdmissionApplication
    {
        return DB::transaction(function () use ($application, $payload, $actor): AdmissionApplication {
            $application = $this->freshFoundation($application);

            if ($application->isRegistered()) {
                AuditLogService::log('Admissions', 'admission_application_registration_reused', $application, null, [
                    'status' => $application->statusCode(),
                ], user: $actor);

                return $application;
            }

            if (! $application->isDraft()) {
                AuditLogService::log('Admissions', 'admission_application_registration_rejected', $application, $this->safeSnapshot($application), [
                    'reason' => 'status_transition_forbidden',
                    'status' => $application->statusCode(),
                ], user: $actor);

                throw ValidationException::withMessages(['status' => 'Зарегистрировать можно только черновик заявления.']);
            }

            if (! $application->applicant_id || ! $application->admission_year || ! $application->education_program_id) {
                throw ValidationException::withMessages([
                    'application' => 'Для регистрации нужны абитуриент, год приема и образовательная программа.',
                ]);
            }

            if (filter_var($payload['confirm_required_fields'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $readiness = $this->documentReadiness->forApplication($application->id);

                if (! ($readiness['internal_complete'] ?? false)) {
                    AuditLogService::log('Admissions', 'admission_application_registration_rejected', $application, $this->safeSnapshot($application), [
                        'reason' => 'documents_not_ready',
                        'blocking_reasons' => $readiness['blocking_reasons'] ?? [],
                    ], user: $actor);

                    throw ValidationException::withMessages([
                        'documents' => 'Нельзя зарегистрировать заявление: комплект документов не готов.',
                    ]);
                }
            }

            $old = $this->safeSnapshot($application);
            $number = $application->application_number ?: $this->generateApplicationNumber($application);
            $this->applicationDocuments->ensureForRegistration($application, $actor);

            $application->update([
                'application_number' => $number,
                'status' => AdmissionApplication::STATUS_REGISTERED,
                'status_id' => $this->referenceItemId('admission_application_statuses', AdmissionApplication::STATUS_REGISTERED),
                'registered_at' => $payload['registered_at'] ?? now(),
                'updated_by' => $actor?->id,
            ]);
            $application->refresh()->load(['applicant.person', 'statusItem', 'source', 'educationProgram.specialty']);

            AuditLogService::log('Admissions', 'admission_application_registered', $application, $old, $this->safeSnapshot($application), user: $actor);

            return $application;
        });
    }

    private function freshFoundation(AdmissionApplication $application): AdmissionApplication
    {
        $fresh = $this->applications->find($application->id);

        if (! $fresh) {
            throw ValidationException::withMessages(['application' => 'Foundation-заявление не найдено.']);
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyPersonSnapshot(Person $person): array
    {
        return [
            'last_name' => $person->last_name,
            'first_name' => $person->first_name,
            'middle_name' => $person->middle_name,
            'birth_date' => $person->birth_date,
            'phone' => $person->phone,
            'email' => $person->email,
        ];
    }

    private function legacyEducationBase(mixed $value): string
    {
        return match ((string) ($value ?: 'after_9')) {
            'basic_general' => 'after_9',
            'secondary_general' => 'after_11',
            'after_11' => 'after_11',
            default => 'after_9',
        };
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

    private function assertApplicationNumberUnique(int $admissionYear, ?string $applicationNumber, ?int $ignoreId = null): void
    {
        if ($applicationNumber === null) {
            return;
        }

        if ($this->applications->applicationNumberExists($admissionYear, $applicationNumber, $ignoreId)) {
            throw ValidationException::withMessages([
                'application_number' => 'Номер заявления уже используется в этом году приема.',
            ]);
        }
    }

    private function generateApplicationNumber(AdmissionApplication $application): string
    {
        $base = 'ADM-'.$application->admission_year.'-'.str_pad((string) $application->id, 5, '0', STR_PAD_LEFT);
        $number = $base;
        $suffix = 1;

        while ($this->applications->applicationNumberExists((int) $application->admission_year, $number, $application->id)) {
            $number = $base.'-'.$suffix++;
        }

        return $number;
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeSnapshot(AdmissionApplication $application): array
    {
        return [
            'id' => $application->id,
            'record_type' => $application->record_type,
            'applicant_id' => $application->applicant_id,
            'admission_year' => $application->admission_year,
            'application_number' => $application->application_number,
            'education_program_id' => $application->education_program_id,
            'status' => $application->statusCode(),
            'status_id' => $application->status_id,
            'source_id' => $application->source_id,
            'submitted_at' => $application->submitted_at instanceof Carbon ? $application->submitted_at->toDateString() : $application->submitted_at,
            'registered_at' => $application->registered_at instanceof Carbon ? $application->registered_at->toISOString() : $application->registered_at,
        ];
    }
}
