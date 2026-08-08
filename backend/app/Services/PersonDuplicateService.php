<?php

namespace App\Services;

use App\Models\Admissions\IdentityDocument;
use App\Models\Person;
use Illuminate\Support\Collection;

class PersonDuplicateService
{
    /**
     * @param array<string, mixed> $payload
     * @return array{criteria: array<string, bool>, matches: array<int, array<string, mixed>>}
     */
    public function check(array $payload): array
    {
        $criteria = $this->criteria($payload);
        $matches = collect();

        $this->matchPeople($payload)->each(function (Person $person) use ($payload, $criteria, $matches): void {
            $matches->put($person->id, [
                'person' => $person,
                'matched_by' => $this->matchedBy($person, $payload, $criteria),
            ]);
        });

        $this->matchIdentityDocuments($payload)->each(function (IdentityDocument $document) use ($matches): void {
            $person = $document->person;
            if (! $person) {
                return;
            }

            $current = $matches->get($person->id, ['person' => $person, 'matched_by' => []]);
            $current['matched_by'][] = 'identity_document';
            $matches->put($person->id, $current);
        });

        return [
            'criteria' => $criteria,
            'matches' => $matches
                ->map(fn (array $match): array => [
                    'person' => $match['person']->loadMissing(['applicants.status', 'applicants.source'])->loadCount([
                        'students',
                        'teachers',
                        'applicants',
                        'applicantApplications',
                        'graduates',
                        'users',
                        'digitalIdentities',
                    ]),
                    'matched_by' => array_values(array_unique($match['matched_by'])),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return Collection<int, Person>
     */
    private function matchPeople(array $payload): Collection
    {
        $normalized = $this->normalize($payload);
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
                        // Дата рождения хранится с нулевым временем, поэтому сравнение
                        // строк «2008-09-01» с «2008-09-01 00:00:00» не находило дублей.
                        ->whereDate('birth_date', $normalized['birth_date'])
                        ->where(function ($query) use ($normalized): void {
                            $query
                                ->where('middle_name', $normalized['middle_name'] ?? null)
                                ->orWhereNull('middle_name');
                        });
                });
            }
        });

        return $hasCriterion ? $query->orderBy('id')->limit(20)->get() : collect();
    }

    /**
     * @param array<string, mixed> $payload
     * @return Collection<int, IdentityDocument>
     */
    private function matchIdentityDocuments(array $payload): Collection
    {
        $hash = $this->identityHash($payload);

        if (! $hash) {
            return collect();
        }

        return IdentityDocument::query()
            ->with(['person.applicants.status', 'person.applicants.source'])
            ->where('number_hash', $hash)
            ->whereNull('archived_at')
            ->whereNull('replaced_at')
            ->orderBy('id')
            ->limit(20)
            ->get();
    }

    /** @param array<string, mixed> $payload */
    private function criteria(array $payload): array
    {
        $normalized = $this->normalize($payload);

        return [
            'snils' => filled($normalized['snils'] ?? null),
            'email' => filled($normalized['email'] ?? null),
            'phone' => filled($normalized['phone'] ?? null),
            'identity_document' => filled($this->identityHash($payload)),
            'full_name_birth_date' => filled($normalized['last_name'] ?? null)
                && filled($normalized['first_name'] ?? null)
                && filled($normalized['birth_date'] ?? null),
        ];
    }

    /** @param array<string, mixed> $payload @param array<string, bool> $criteria @return list<string> */
    private function matchedBy(Person $person, array $payload, array $criteria): array
    {
        $normalized = $this->normalize($payload);
        $matched = [];

        foreach (['snils', 'email', 'phone'] as $field) {
            if ($criteria[$field] && $person->{$field} === $normalized[$field]) {
                $matched[] = $field;
            }
        }

        if ($criteria['full_name_birth_date']
            && $person->last_name === $normalized['last_name']
            && $person->first_name === $normalized['first_name']
            && $person->birth_date?->toDateString() === $normalized['birth_date']
        ) {
            $matched[] = 'full_name_birth_date';
        }

        return $matched;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function normalize(array $payload): array
    {
        return [
            'last_name' => trim((string) ($payload['last_name'] ?? '')),
            'first_name' => trim((string) ($payload['first_name'] ?? '')),
            'middle_name' => $this->blankToNull($payload['middle_name'] ?? null),
            'birth_date' => $this->blankToNull($payload['birth_date'] ?? null),
            'snils' => $this->digits($payload['snils'] ?? null),
            'email' => ($email = $this->blankToNull($payload['email'] ?? null)) ? mb_strtolower($email) : null,
            'phone' => $this->digits($payload['phone'] ?? null),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function identityHash(array $payload): ?string
    {
        $document = is_array($payload['identity_document'] ?? null) ? $payload['identity_document'] : [];
        $series = $this->blankToNull($document['series'] ?? $payload['identity_series'] ?? null);
        $number = $this->blankToNull($document['number'] ?? $payload['identity_number'] ?? null);

        if (! $series && ! $number) {
            return null;
        }

        return hash('sha256', mb_strtolower(trim((string) $series).'|'.trim((string) $number)));
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function digits(mixed $value): ?string
    {
        $value = $this->blankToNull($value);

        return $value ? preg_replace('/\D+/', '', $value) : null;
    }
}
