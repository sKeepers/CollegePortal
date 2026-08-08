<?php

namespace App\Services\Admissions;

use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Выбор действующего документа человека. Единственное место, где решается,
 * какой из паспортов или документов об образовании считается текущим: и приёмная
 * комиссия, и карточка студента спрашивают об этом здесь.
 */
class PersonDocumentService
{
    public function currentIdentity(?int $personId): ?IdentityDocument
    {
        if ($personId === null) {
            return null;
        }

        return $this->currentIdentityForPeople([$personId])->get($personId);
    }

    public function currentEducation(?int $personId): ?EducationDocument
    {
        if ($personId === null) {
            return null;
        }

        return $this->currentEducationForPeople([$personId])->get($personId);
    }

    /**
     * @param list<int> $personIds
     * @return Collection<int, IdentityDocument> ключ — person_id
     */
    public function currentIdentityForPeople(array $personIds): Collection
    {
        return $this->pickCurrent(
            IdentityDocument::query()->with(['activeFiles', 'documentType'])->whereIn('verification_status', IdentityDocument::ACTIVE_STATUSES),
            $personIds,
        );
    }

    /**
     * @param list<int> $personIds
     * @return Collection<int, EducationDocument> ключ — person_id
     */
    public function currentEducationForPeople(array $personIds): Collection
    {
        return $this->pickCurrent(
            EducationDocument::query()->with(['activeFiles', 'documentType'])->whereIn('verification_status', EducationDocument::ACTIVE_STATUSES),
            $personIds,
        );
    }

    /**
     * @param list<int> $personIds
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    private function pickCurrent(Builder $query, array $personIds): Collection
    {
        $personIds = array_values(array_unique(array_filter($personIds, fn ($id): bool => $id !== null)));

        if ($personIds === []) {
            return collect();
        }

        return $query
            ->current()
            ->whereIn('person_id', $personIds)
            ->orderByDesc('is_primary')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('person_id')
            ->map(fn (Collection $documents) => $documents->first());
    }
}
