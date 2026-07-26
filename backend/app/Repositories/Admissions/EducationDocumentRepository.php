<?php

namespace App\Repositories\Admissions;

use App\Models\Admissions\EducationDocument;
use Illuminate\Support\Collection;

class EducationDocumentRepository
{
    /** @return Collection<int, EducationDocument> */
    public function listForApplicant(int $applicantId, bool $withArchived = false): Collection
    {
        return EducationDocument::query()
            ->with(['documentType', 'country', 'educationLevel', 'activeFiles'])
            ->where('applicant_id', $applicantId)
            ->when(! $withArchived, fn ($query) => $query->active())
            ->orderByDesc('is_primary')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();
    }

    public function find(int $id, bool $withArchived = false): ?EducationDocument
    {
        return EducationDocument::query()
            ->with(['applicant.person', 'documentType', 'country', 'educationLevel', 'activeFiles'])
            ->when(! $withArchived, fn ($query) => $query->active())
            ->find($id);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): EducationDocument
    {
        return EducationDocument::query()
            ->create($data)
            ->load(['applicant.person', 'documentType', 'country', 'educationLevel', 'activeFiles']);
    }
}
