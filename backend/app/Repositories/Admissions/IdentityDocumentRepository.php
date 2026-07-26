<?php

namespace App\Repositories\Admissions;

use App\Models\Admissions\IdentityDocument;
use Illuminate\Support\Collection;

class IdentityDocumentRepository
{
    /** @return Collection<int, IdentityDocument> */
    public function listForApplicant(int $applicantId, bool $withArchived = false): Collection
    {
        return IdentityDocument::query()
            ->with(['documentType', 'releaseCountry', 'activeFiles'])
            ->where('applicant_id', $applicantId)
            ->when(! $withArchived, fn ($query) => $query->active())
            ->orderByDesc('is_primary')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();
    }

    public function find(int $id, bool $withArchived = false): ?IdentityDocument
    {
        return IdentityDocument::query()
            ->with(['applicant.person', 'documentType', 'releaseCountry', 'activeFiles'])
            ->when(! $withArchived, fn ($query) => $query->active())
            ->find($id);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): IdentityDocument
    {
        return IdentityDocument::query()
            ->create($data)
            ->load(['applicant.person', 'documentType', 'releaseCountry', 'activeFiles']);
    }
}
