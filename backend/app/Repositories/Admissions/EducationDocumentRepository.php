<?php

namespace App\Repositories\Admissions;

use App\Models\Admissions\EducationDocument;
use Illuminate\Support\Collection;

class EducationDocumentRepository
{
    /**
     * Документы принадлежат человеку, а не роли, поэтому список строится по `person_id`:
     * абитуриент, студент и выпускник видят одну и ту же историю документов.
     *
     * @return Collection<int, EducationDocument>
     */
    public function listForPerson(int $personId, bool $withArchived = false): Collection
    {
        return EducationDocument::query()
            ->with(['documentType', 'country', 'educationLevel', 'activeFiles'])
            ->where('person_id', $personId)
            ->when(! $withArchived, fn ($query) => $query->current())
            ->orderByDesc('is_primary')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();
    }

    public function find(int $id, bool $withArchived = false): ?EducationDocument
    {
        return EducationDocument::query()
            ->with(['person', 'applicant.person', 'documentType', 'country', 'educationLevel', 'activeFiles'])
            ->when(! $withArchived, fn ($query) => $query->active())
            ->find($id);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): EducationDocument
    {
        return EducationDocument::query()
            ->create($data)
            ->load(['person', 'applicant.person', 'documentType', 'country', 'educationLevel', 'activeFiles']);
    }
}
