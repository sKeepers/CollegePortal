<?php

namespace App\Repositories\Admissions;

use App\Models\Person;
use Illuminate\Database\Eloquent\Collection;

/**
 * Repository поиска и создания Person для приемной комиссии.
 */
class PersonRepository
{
    public function find(int $id): ?Person
    {
        return Person::query()->find($id);
    }

    /**
     * Ищет возможные совпадения без автоматического объединения только по ФИО.
     *
     * @param array<string, mixed> $data
     * @return Collection<int, Person>
     */
    public function findPossibleMatches(array $data): Collection
    {
        $normalized = $this->normalize($data);
        $query = Person::query();
        $hasCriterion = false;

        $query->where(function ($query) use ($normalized, &$hasCriterion): void {
            foreach (['snils', 'email', 'phone'] as $field) {
                if (! empty($normalized[$field])) {
                    $hasCriterion = true;
                    $query->orWhere($field, $normalized[$field]);
                }
            }

            if (! empty($normalized['last_name']) && ! empty($normalized['first_name']) && ! empty($normalized['birth_date'])) {
                $hasCriterion = true;
                $query->orWhere(function ($query) use ($normalized): void {
                    $query
                        ->where('last_name', $normalized['last_name'])
                        ->where('first_name', $normalized['first_name'])
                        ->where('birth_date', $normalized['birth_date'])
                        ->where(function ($middleNameQuery) use ($normalized): void {
                            $middleNameQuery
                                ->where('middle_name', $normalized['middle_name'] ?? null)
                                ->orWhereNull('middle_name');
                        });
                });
            }
        });

        return $hasCriterion ? $query->orderBy('id')->limit(10)->get() : new Collection();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Person
    {
        return Person::query()->create($this->normalize($data));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        return [
            'uuid' => $this->blankToNull($data['uuid'] ?? null),
            'last_name' => trim((string) ($data['last_name'] ?? '')),
            'first_name' => trim((string) ($data['first_name'] ?? '')),
            'middle_name' => $this->blankToNull($data['middle_name'] ?? null),
            'birth_date' => $this->blankToNull($data['birth_date'] ?? null),
            'gender' => $this->blankToNull($data['gender'] ?? null),
            'citizenship' => $this->blankToNull($data['citizenship'] ?? null),
            'place_birth' => $this->blankToNull($data['place_birth'] ?? null),
            'phone' => $this->normalizeDigits($data['phone'] ?? null),
            'email' => $this->normalizeEmail($data['email'] ?? null),
            'address' => $this->blankToNull($data['address'] ?? null),
            'photo_path' => $this->blankToNull($data['photo_path'] ?? null),
            'snils' => $this->normalizeDigits($data['snils'] ?? null),
            'inn' => $this->normalizeDigits($data['inn'] ?? null),
            'status' => $this->blankToNull($data['status'] ?? null) ?: 'active',
        ];
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $value = $this->blankToNull($value);

        return $value ? mb_strtolower($value) : null;
    }

    private function normalizeDigits(mixed $value): ?string
    {
        $value = $this->blankToNull($value);

        return $value ? preg_replace('/\D+/', '', $value) : null;
    }
}
