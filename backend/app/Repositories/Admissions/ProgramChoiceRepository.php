<?php

namespace App\Repositories\Admissions;

use App\Models\Admissions\ProgramChoice;
use Illuminate\Database\Eloquent\Collection;

class ProgramChoiceRepository
{
    /**
     * @return Collection<int, ProgramChoice>
     */
    public function forApplication(int $applicationId): Collection
    {
        return ProgramChoice::query()
            ->active()
            ->with($this->relations())
            ->where('application_id', $applicationId)
            ->orderBy('priority')
            ->get();
    }

    public function findActive(int $id): ?ProgramChoice
    {
        return ProgramChoice::query()
            ->active()
            ->with($this->relations())
            ->find($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): ProgramChoice
    {
        return ProgramChoice::query()
            ->create($data)
            ->load($this->relations());
    }

    public function activeCount(int $applicationId): int
    {
        return ProgramChoice::query()
            ->active()
            ->where('application_id', $applicationId)
            ->count();
    }

    public function priorityExists(int $applicationId, int $priority, ?int $ignoreId = null): bool
    {
        return ProgramChoice::query()
            ->active()
            ->where('application_id', $applicationId)
            ->where('priority', $priority)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    public function programExists(int $applicationId, int $educationProgramId, ?int $ignoreId = null): bool
    {
        return ProgramChoice::query()
            ->active()
            ->where('application_id', $applicationId)
            ->where('education_program_id', $educationProgramId)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    public function compactPriorities(int $applicationId): void
    {
        $choices = ProgramChoice::query()
            ->active()
            ->where('application_id', $applicationId)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($choices as $index => $choice) {
            $choice->update([
                'priority' => $index + 1,
                'is_primary' => $index === 0,
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'application',
            'educationProgram.specialty',
            'specialty',
            'educationForm',
            'fundingForm',
            'baseEducationType',
            'quotaType',
            'status',
        ];
    }
}
