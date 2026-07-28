<?php

namespace App\Repositories\Admissions;

use App\Models\Admissions\Applicant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Repository доступа к foundation-профилям абитуриентов.
 */
class ApplicantRepository
{
    /**
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<Applicant>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 100));

        return Applicant::query()
            ->with(['person', 'status', 'source', 'responsibleUser'])
            ->when($filters['q'] ?? null, function ($query, string $search): void {
                $query->whereHas('person', function ($personQuery) use ($search): void {
                    $personQuery
                        ->where('last_name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status): mixed => $query->whereHas('status', fn ($statusQuery) => $statusQuery->where('code', $status)))
            ->when($filters['source'] ?? null, fn ($query, string $source): mixed => $query->whereHas('source', fn ($sourceQuery) => $sourceQuery->where('code', $source)))
            ->when($filters['responsible_user_id'] ?? null, fn ($query, int $userId): mixed => $query->where('responsible_user_id', $userId))
            ->when(! filter_var($filters['with_archived'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($query) => $query->active())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(int $id): ?Applicant
    {
        return Applicant::query()
            ->with(['person', 'status', 'source', 'responsibleUser'])
            ->find($id);
    }

    public function findByPersonId(int $personId): ?Applicant
    {
        return Applicant::query()
            ->with(['person', 'status', 'source', 'responsibleUser'])
            ->where('person_id', $personId)
            ->whereNull('archived_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Applicant
    {
        return Applicant::query()->create($data)->load(['person', 'status', 'source', 'responsibleUser']);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Applicant $applicant, array $data): Applicant
    {
        $applicant->update($data);

        return $applicant->refresh()->load(['person', 'status', 'source', 'responsibleUser']);
    }
}
