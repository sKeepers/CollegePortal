<?php

namespace App\Services;

use App\Models\Person;
use App\Models\Student;
use App\Services\Admissions\SnilsService;
use Illuminate\Support\Collection;

/**
 * Связь карточки студента с личной карточкой человека.
 *
 * СНИЛС — единственный точный ключ дедупликации: `Person` создаётся или переиспользуется
 * по `snils_hash`. Без СНИЛС точного ключа нет, поэтому совпадения по ФИО и дате рождения
 * только показываются оператору как вероятные дубли — связывать по ним молча нельзя.
 */
class StudentPersonService
{
    public function __construct(
        private readonly PersonService $people,
        private readonly SnilsService $snils,
    ) {
    }

    /**
     * @param array<string, mixed> $data данные студента; `snils` уже нормализован либо пуст
     * @return array{person: Person, duplicate_candidates: list<array<string, mixed>>}
     */
    public function resolveForData(array $data, ?Person $current = null): array
    {
        if ($current !== null) {
            return ['person' => $current, 'duplicate_candidates' => []];
        }

        $hash = $this->snils->hash($data['snils'] ?? null);

        if ($hash !== null) {
            $person = Person::query()->where('snils_hash', $hash)->first();

            if ($person) {
                return ['person' => $person, 'duplicate_candidates' => []];
            }
        }

        // Ни точного ключа, ни найденного человека: заводим нового и предупреждаем
        // о вероятных совпадениях, чтобы оператор объединил карточки осознанно.
        $candidates = $this->people->findPossibleDuplicates($data);
        $person = $this->people->createPerson($data);

        return [
            'person' => $person,
            'duplicate_candidates' => $this->describe($candidates, $person),
        ];
    }

    /**
     * Гарантирует, что у студента есть человек: без него нельзя прикрепить ни паспорт,
     * ни документ об образовании.
     *
     * @return array{person: Person, duplicate_candidates: list<array<string, mixed>>}
     */
    public function ensureForStudent(Student $student): array
    {
        if ($student->person_id !== null && $student->person) {
            return ['person' => $student->person, 'duplicate_candidates' => []];
        }

        $resolved = $this->resolveForData([
            'last_name' => $student->last_name,
            'first_name' => $student->first_name,
            'middle_name' => $student->middle_name,
            'birth_date' => $student->birth_date?->toDateString(),
            'phone' => $student->phone,
            'email' => $student->email,
            'address' => $student->address,
            'photo_path' => $student->photo_path,
            'snils' => $student->snils,
        ]);

        $this->people->linkProfile($student, $resolved['person']);
        $student->refresh();

        return $resolved;
    }

    /**
     * @param Collection<int, Person> $candidates
     * @return list<array<string, mixed>>
     */
    private function describe(Collection $candidates, Person $exclude): array
    {
        return $candidates
            ->reject(fn (Person $person): bool => $person->id === $exclude->id)
            ->map(fn (Person $person): array => [
                'person_id' => $person->id,
                'full_name' => trim(implode(' ', array_filter([$person->last_name, $person->first_name, $person->middle_name]))),
                'birth_date' => $person->birth_date?->toDateString(),
            ])
            ->values()
            ->all();
    }
}
