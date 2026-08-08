<?php

namespace App\Repositories\Admissions;

use App\Models\Admissions\IdentityDocument;
use Illuminate\Support\Collection;

class IdentityDocumentRepository
{
    /**
     * Документы принадлежат человеку, а не роли, поэтому список строится по `person_id`:
     * абитуриент, студент и выпускник видят одну и ту же историю документов.
     *
     * @return Collection<int, IdentityDocument>
     */
    public function listForPerson(int $personId, bool $withArchived = false): Collection
    {
        return IdentityDocument::query()
            ->with(['documentType', 'releaseCountry', 'activeFiles'])
            ->where('person_id', $personId)
            ->when(! $withArchived, fn ($query) => $query->current())
            ->orderByDesc('is_primary')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();
    }

    public function find(int $id, bool $withArchived = false): ?IdentityDocument
    {
        return IdentityDocument::query()
            ->with(['person', 'applicant.person', 'documentType', 'releaseCountry', 'activeFiles'])
            ->when(! $withArchived, fn ($query) => $query->active())
            ->find($id);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): IdentityDocument
    {
        return IdentityDocument::query()
            ->create($data)
            ->load(['person', 'applicant.person', 'documentType', 'releaseCountry', 'activeFiles']);
    }
}
