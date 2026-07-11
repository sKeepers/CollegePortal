<?php

namespace App\Services\Bulk;

use App\Models\ApplicantApplication;
use App\Models\ApplicantApplicationDocument;
use App\Models\Student;
use App\Services\ApplicantApplicationDocumentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BulkSelectionResolver
{
    /** @return Collection<int, ApplicantApplication> */
    public function admissions(array $selection): Collection
    {
        return $this->applyAdmissionSelection(ApplicantApplication::query()->with(['educationProgram.specialty', 'documents']), $selection)->get();
    }

    /** @return Collection<int, Student> */
    public function students(array $selection): Collection
    {
        return $this->applyStudentSelection(Student::query()->with('group'), $selection)->get();
    }

    public function applyAdmissionSelection(Builder $query, array $selection): Builder
    {
        $ids = collect($selection['ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isNotEmpty()) {
            return $query->whereIn('id', $ids);
        }

        $filter = $selection['filter'] ?? [];
        $operator = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query
            ->when($filter['search'] ?? null, function (Builder $query, string $search) use ($operator): void {
                $query->where(function (Builder $query) use ($operator, $search): void {
                    $query->where('last_name', $operator, "%{$search}%")
                        ->orWhere('first_name', $operator, "%{$search}%")
                        ->orWhere('middle_name', $operator, "%{$search}%")
                        ->orWhere('phone', $operator, "%{$search}%")
                        ->orWhere('email', $operator, "%{$search}%");
                });
            })
            ->when($filter['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filter['educationProgramId'] ?? $filter['education_program_id'] ?? null, fn (Builder $query, mixed $id) => $query->where('education_program_id', (int) $id))
            ->when($filter['specialtyId'] ?? $filter['specialty_id'] ?? null, function (Builder $query, mixed $id): void {
                $query->whereHas('educationProgram', fn (Builder $query) => $query->where('specialty_id', (int) $id));
            })
            ->when($filter['submittedDate'] ?? $filter['submitted_at'] ?? null, fn (Builder $query, string $date) => $query->whereDate('submitted_at', $date))
            ->when($filter['documents_status'] ?? $filter['documentsStatus'] ?? $filter['completeness'] ?? null, function (Builder $query, string $status): void {
                $status = $status === 'empty' ? 'no_documents' : $status;
                $required = ApplicantApplicationDocumentService::REQUIRED_DOCUMENTS_COUNT;

                if ($status === 'no_documents') {
                    $query->whereDoesntHave('documents', fn (Builder $query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES));
                } elseif ($status === 'complete') {
                    $query->whereHas('documents', fn (Builder $query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES), '>=', $required);
                } elseif ($status === 'incomplete') {
                    $query
                        ->whereHas('documents', fn (Builder $query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES), '>=', 1)
                        ->whereHas('documents', fn (Builder $query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES), '<', $required);
                }
            });
    }

    public function applyStudentSelection(Builder $query, array $selection): Builder
    {
        $ids = collect($selection['ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isNotEmpty()) {
            return $query->whereIn('id', $ids);
        }

        $filter = $selection['filter'] ?? [];
        $operator = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query
            ->when($filter['search'] ?? null, function (Builder $query, string $search) use ($operator): void {
                $query->where(function (Builder $query) use ($operator, $search): void {
                    $query->where('last_name', $operator, "%{$search}%")
                        ->orWhere('first_name', $operator, "%{$search}%")
                        ->orWhere('middle_name', $operator, "%{$search}%");
                });
            })
            ->when($filter['group_id'] ?? null, fn (Builder $query, mixed $id) => $query->where('group_id', (int) $id))
            ->when($filter['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status));
    }
}
