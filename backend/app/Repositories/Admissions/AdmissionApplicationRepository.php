<?php

namespace App\Repositories\Admissions;

use App\Models\Admissions\AdmissionApplication;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Repository read/write-доступа к foundation-заявлениям приемной комиссии.
 */
class AdmissionApplicationRepository
{
    /**
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<AdmissionApplication>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 100));

        return AdmissionApplication::query()
            ->foundation()
            ->with(['applicant.person', 'statusItem', 'source', 'educationProgram.specialty'])
            ->when($filters['applicant_id'] ?? null, fn ($query, int|string $applicantId) => $query->where('applicant_id', $applicantId))
            ->when($filters['admission_year'] ?? null, fn ($query, int|string $year) => $query->where('admission_year', $year))
            ->when($filters['status'] ?? null, function ($query, string $status): void {
                $query->where(function ($statusQuery) use ($status): void {
                    $statusQuery
                        ->where('status', $status)
                        ->orWhereHas('statusItem', fn ($itemQuery) => $itemQuery->where('code', $status));
                });
            })
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where('application_number', 'like', "%{$search}%"))
            ->when(! filter_var($filters['with_archived'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($query) => $query->active())
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(int $id): ?AdmissionApplication
    {
        return AdmissionApplication::query()
            ->foundation()
            ->with(['applicant.person', 'statusItem', 'source', 'educationProgram.specialty'])
            ->find($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): AdmissionApplication
    {
        return AdmissionApplication::query()
            ->create($data)
            ->load(['applicant.person', 'statusItem', 'source', 'educationProgram.specialty']);
    }

    public function applicationNumberExists(int $admissionYear, string $applicationNumber, ?int $ignoreId = null): bool
    {
        return AdmissionApplication::query()
            ->where('admission_year', $admissionYear)
            ->where('application_number', $applicationNumber)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
