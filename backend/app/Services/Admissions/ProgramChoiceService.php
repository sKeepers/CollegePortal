<?php

namespace App\Services\Admissions;

use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\ProgramChoice;
use App\Models\EducationProgram;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\User;
use App\Repositories\Admissions\AdmissionApplicationRepository;
use App\Repositories\Admissions\ProgramChoiceRepository;
use App\Services\AuditLogService;
use App\Services\SettingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgramChoiceService
{
    public function __construct(
        private readonly AdmissionApplicationRepository $applications,
        private readonly ProgramChoiceRepository $choices,
    ) {
    }

    /**
     * @return Collection<int, ProgramChoice>
     */
    public function listForApplication(int $applicationId): Collection
    {
        $application = $this->foundationApplication($applicationId);

        return $this->choices->forApplication($application->id);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(int $applicationId, array $payload, ?User $actor = null): ProgramChoice
    {
        return DB::transaction(function () use ($applicationId, $payload, $actor): ProgramChoice {
            $application = $this->editableApplication($applicationId);
            $program = EducationProgram::query()->with('specialty')->find((int) $payload['education_program_id']);

            if (! $program) {
                throw ValidationException::withMessages(['education_program_id' => 'Образовательная программа не найдена.']);
            }

            $priority = (int) $payload['priority'];
            $this->assertCanUsePriority($application->id, $priority);
            $this->assertCanUseProgram($application->id, $program->id);
            $this->assertWithinMaxChoices($application->id);

            $choice = $this->choices->create([
                'application_id' => $application->id,
                'priority' => $priority,
                'specialty_id' => $program->specialty_id,
                'education_program_id' => $program->id,
                'education_form_id' => $this->referenceItemId('education_forms', $payload['education_form_id'] ?? null),
                'funding_form_id' => $this->referenceItemId('funding_forms', $payload['funding_form_id'] ?? null),
                'base_education_type_id' => $this->referenceItemId('base_education_types', $payload['base_education_type_id'] ?? null),
                'quota_type_id' => $this->referenceItemId('quota_types', $payload['quota_type_id'] ?? null),
                'status_id' => $this->referenceItemId('application_choice_statuses', $payload['status_id'] ?? null, 'active'),
                'is_primary' => $priority === 1,
                'metadata' => $payload['metadata'] ?? null,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            $this->assertPrioritySequence($application->id);
            AuditLogService::log('Admissions', 'program_choice_created', $choice, null, $this->snapshot($choice), user: $actor);

            return $choice->refresh()->load([
                'educationProgram.specialty',
                'specialty',
                'educationForm',
                'fundingForm',
                'baseEducationType',
                'quotaType',
                'status',
            ]);
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $choiceId, array $payload, ?User $actor = null): ProgramChoice
    {
        return DB::transaction(function () use ($choiceId, $payload, $actor): ProgramChoice {
            $choice = $this->activeChoice($choiceId);
            $application = $this->editableApplication((int) $choice->application_id);
            $old = $this->snapshot($choice);
            $updates = collect($payload)->only([
                'priority',
                'education_program_id',
                'education_form_id',
                'funding_form_id',
                'base_education_type_id',
                'quota_type_id',
                'status_id',
                'metadata',
            ])->all();

            if (array_key_exists('priority', $updates)) {
                $priority = (int) $updates['priority'];
                $this->assertCanUsePriority($application->id, $priority, $choice->id);
                $updates['priority'] = $priority;
                $updates['is_primary'] = $priority === 1;
            }

            if (array_key_exists('education_program_id', $updates)) {
                $program = EducationProgram::query()->find((int) $updates['education_program_id']);
                if (! $program) {
                    throw ValidationException::withMessages(['education_program_id' => 'Образовательная программа не найдена.']);
                }
                $this->assertCanUseProgram($application->id, $program->id, $choice->id);
                $updates['education_program_id'] = $program->id;
                $updates['specialty_id'] = $program->specialty_id;
            }

            foreach ([
                'education_form_id' => 'education_forms',
                'funding_form_id' => 'funding_forms',
                'base_education_type_id' => 'base_education_types',
                'quota_type_id' => 'quota_types',
                'status_id' => 'application_choice_statuses',
            ] as $field => $catalogCode) {
                if (array_key_exists($field, $updates)) {
                    $updates[$field] = $this->referenceItemId($catalogCode, $updates[$field]);
                }
            }

            $updates['updated_by'] = $actor?->id;
            $choice->update($updates);
            $this->assertPrioritySequence($application->id);
            $choice->refresh()->load([
                'educationProgram.specialty',
                'specialty',
                'educationForm',
                'fundingForm',
                'baseEducationType',
                'quotaType',
                'status',
            ]);

            AuditLogService::log('Admissions', 'program_choice_updated', $choice, $old, $this->snapshot($choice), user: $actor);

            return $choice;
        });
    }

    public function delete(int $choiceId, ?User $actor = null): void
    {
        DB::transaction(function () use ($choiceId, $actor): void {
            $choice = $this->activeChoice($choiceId);
            $application = $this->editableApplication((int) $choice->application_id);
            $old = $this->snapshot($choice);

            $choice->update([
                'archived_at' => now(),
                'updated_by' => $actor?->id,
            ]);
            $this->choices->compactPriorities($application->id);

            AuditLogService::log('Admissions', 'program_choice_deleted', $choice, $old, [
                'id' => $choice->id,
                'application_id' => $choice->application_id,
                'archived_at' => $choice->archived_at?->toISOString(),
            ], user: $actor);
        });
    }

    private function foundationApplication(int $applicationId): AdmissionApplication
    {
        $application = $this->applications->find($applicationId);

        if (! $application) {
            throw ValidationException::withMessages(['application' => 'Foundation-заявление не найдено.']);
        }

        return $application;
    }

    private function editableApplication(int $applicationId): AdmissionApplication
    {
        $application = $this->foundationApplication($applicationId);

        if (! $application->isDraft()) {
            throw ValidationException::withMessages(['application' => 'Выбранные программы можно менять только у черновика заявления.']);
        }

        return $application;
    }

    private function activeChoice(int $choiceId): ProgramChoice
    {
        $choice = $this->choices->findActive($choiceId);

        if (! $choice) {
            throw ValidationException::withMessages(['choice' => 'Выбранная программа не найдена.']);
        }

        $this->foundationApplication((int) $choice->application_id);

        return $choice;
    }

    private function assertWithinMaxChoices(int $applicationId): void
    {
        $max = max(1, (int) SettingService::value('admissions', 'max_choices_per_application', 5));

        if ($this->choices->activeCount($applicationId) >= $max) {
            throw ValidationException::withMessages(['choices' => "Достигнут максимум выбранных программ: {$max}."]);
        }
    }

    private function assertCanUsePriority(int $applicationId, int $priority, ?int $ignoreId = null): void
    {
        $maxPriority = $this->choices->activeCount($applicationId) + ($ignoreId === null ? 1 : 0);

        if ($priority < 1 || $priority > $maxPriority) {
            throw ValidationException::withMessages(['priority' => 'Приоритеты должны идти последовательно от 1 без пропусков.']);
        }

        if ($this->choices->priorityExists($applicationId, $priority, $ignoreId)) {
            throw ValidationException::withMessages(['priority' => 'Приоритет уже используется в этом заявлении.']);
        }
    }

    private function assertCanUseProgram(int $applicationId, int $educationProgramId, ?int $ignoreId = null): void
    {
        if ($this->choices->programExists($applicationId, $educationProgramId, $ignoreId)) {
            throw ValidationException::withMessages(['education_program_id' => 'Эта образовательная программа уже выбрана в заявлении.']);
        }
    }

    private function assertPrioritySequence(int $applicationId): void
    {
        $priorities = $this->choices->forApplication($applicationId)
            ->pluck('priority')
            ->values()
            ->all();

        if ($priorities !== range(1, count($priorities))) {
            throw ValidationException::withMessages(['priority' => 'Приоритеты должны идти последовательно от 1 без пропусков.']);
        }
    }

    private function referenceItemId(string $catalogCode, mixed $id, ?string $defaultCode = null): ?int
    {
        if ($id === null && $defaultCode === null) {
            return null;
        }

        $catalogId = ReferenceCatalog::query()->where('code', $catalogCode)->value('id');
        $query = ReferenceItem::query()->where('catalog_id', $catalogId);
        $item = $id !== null
            ? $query->whereKey((int) $id)->first()
            : $query->where('code', $defaultCode)->first();

        if (! $catalogId || ! $item) {
            throw ValidationException::withMessages([$catalogCode => "Не найден элемент справочника {$catalogCode}."]);
        }

        return (int) $item->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ProgramChoice $choice): array
    {
        return [
            'id' => $choice->id,
            'application_id' => $choice->application_id,
            'priority' => $choice->priority,
            'education_program_id' => $choice->education_program_id,
            'education_form_id' => $choice->education_form_id,
            'funding_form_id' => $choice->funding_form_id,
            'base_education_type_id' => $choice->base_education_type_id,
            'quota_type_id' => $choice->quota_type_id,
            'status_id' => $choice->status_id,
            'archived_at' => $choice->archived_at?->toISOString(),
        ];
    }
}
