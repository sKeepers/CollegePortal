<?php

namespace App\Services;

use App\Models\ApplicantApplication;
use App\Models\DigitalIdentity;
use App\Models\Graduate;
use App\Models\Person;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PersonService
{
    /** Общие поля, которые профильная карточка вправе записать в Person. */
    private const PROFILE_EDITABLE_FIELDS = ['last_name', 'first_name', 'middle_name', 'phone', 'email', 'snils'];

    /**
     * Профили, держащие собственную копию общих данных, и поля этой копии.
     * `employees` здесь нет намеренно: своих ФИО и контактов у кадровой записи не бывает,
     * она читает человека напрямую — списывать ей нечего.
     */
    private const PROFILE_MIRRORS = [
        Teacher::class => ['last_name', 'first_name', 'middle_name', 'phone', 'email'],
        Student::class => ['last_name', 'first_name', 'middle_name', 'phone', 'email'],
    ];

    public function createPerson(array $data): Person
    {
        return Person::create($this->normalizePersonData($data));
    }

    /** @return Collection<int, Person> */
    public function findPossibleDuplicates(array $data): Collection
    {
        $normalized = $this->normalizePersonData($data);
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
                            $query->where('middle_name', $normalized['middle_name'] ?? null)
                                ->orWhereNull('middle_name');
                        });
                });
            }
        });

        return $hasCriterion ? $query->limit(10)->get() : collect();
    }

    public function linkProfile(Model $profile, Person $person): void
    {
        if (! $this->supportsPerson($profile)) {
            return;
        }

        $profile->forceFill(['person_id' => $person->id])->save();

        if ($profile instanceof Student || $profile instanceof Teacher) {
            if ($profile->user_id) {
                User::whereKey($profile->user_id)->update(['person_id' => $person->id, 'person_type' => 'person']);
            }

            DigitalIdentity::query()
                ->where('entity_type', $profile instanceof Student ? DigitalIdentity::ENTITY_STUDENT : DigitalIdentity::ENTITY_TEACHER)
                ->where('entity_id', $profile->id)
                ->update(['person_id' => $person->id]);
        }

        if ($profile instanceof Graduate && $profile->student?->user_id) {
            User::whereKey($profile->student->user_id)->update(['person_id' => $person->id, 'person_type' => 'person']);
        }
    }

    /**
     * Обновление общих данных человека. Единственная точка записи ФИО и контактов:
     * профильные карточки приходят сюда, а не правят свои копии в обход Person.
     *
     * Набор полей почти всегда частичный — карточка сотрудника шлёт ФИО и контакты,
     * карточка человека шлёт всё. Недостающее берётся из текущей записи: без этого
     * `normalizePersonData` обнулял бы всё, чего не было в запросе.
     */
    public function updateSharedData(Person $person, array $data): Person
    {
        $person->update($this->normalizePersonData(array_merge($this->currentSharedData($person), $data)));
        $this->syncProfiles($person, array_keys($person->getChanges()));

        return $person->fresh();
    }

    /**
     * Обновление общих данных из профильной карточки — сотрудника или преподавателя.
     *
     * Профильная карточка видит человека не целиком: СНИЛС в ней скрыт без права
     * `people.update`. Поэтому пустое поле здесь означает «не менять», а не «очистить»,
     * иначе сохранение карточки сотрудника стирало бы то, чего оператор даже не видел.
     * Очистить общее поле можно в карточке человека, где видно всё, что меняется.
     */
    public function updateFromProfile(Person $person, array $data): Person
    {
        $filled = array_filter(
            array_intersect_key($data, array_flip(self::PROFILE_EDITABLE_FIELDS)),
            static fn (mixed $value): bool => filled(is_string($value) ? trim($value) : $value),
        );

        return $filled === [] ? $person : $this->updateSharedData($person, $filled);
    }

    /**
     * Раскладывает общие данные по профилям, которые хранят собственную копию ФИО и контактов.
     *
     * Копии в `teachers` и `students` оставлены намеренно: на них держатся журнал,
     * расписание, нагрузка, экзамены, реестры и выгрузки. Значит, они обязаны быть
     * зеркалом, а не вторым источником правды.
     *
     * @param list<string> $changed поля, которые действительно изменились; пустой список — все общие.
     *                              Синхронизация ровно изменённого не даёт правке фамилии
     *                              заодно затереть телефон, которого в Person ещё нет.
     */
    public function syncProfiles(Person $person, array $changed = []): void
    {
        foreach (self::PROFILE_MIRRORS as $model => $mirrored) {
            $fields = $changed === [] ? $mirrored : array_values(array_intersect($mirrored, $changed));

            if ($fields === []) {
                continue;
            }

            $mirror = collect($fields)->mapWithKeys(fn (string $field): array => [$field => $person->{$field}])->all();

            $model::query()->where('person_id', $person->id)->update($mirror);
        }
    }

    /** @return array<string, mixed> */
    private function currentSharedData(Person $person): array
    {
        return [
            'uuid' => $person->uuid,
            'last_name' => $person->last_name,
            'first_name' => $person->first_name,
            'middle_name' => $person->middle_name,
            'birth_date' => $person->birth_date?->toDateString(),
            'gender' => $person->gender,
            'citizenship' => $person->citizenship,
            'place_birth' => $person->place_birth,
            'phone' => $person->phone,
            'email' => $person->email,
            'address' => $person->address,
            'photo_path' => $person->photo_path,
            'snils' => $person->snils,
            'inn' => $person->inn,
            'status' => $person->status,
        ];
    }

    public function profiles(Person $person): array
    {
        $person->loadMissing([
            'students.group',
            'teachers.subjects',
            'employees.primaryDepartment',
            'employees.primaryPosition',
            'applicants.status',
            'applicants.source',
            'applicantApplications.educationProgram',
            'graduates.student',
            'graduates.group',
            'users.roles',
            'digitalIdentities',
        ]);

        return [
            'students' => $person->students,
            'teachers' => $person->teachers,
            'employees' => $person->employees,
            'applicants' => $person->applicants,
            'applicant_applications' => $person->applicantApplications,
            'graduates' => $person->graduates,
            'users' => $person->users,
            'digital_identities' => $person->digitalIdentities,
        ];
    }

    public function dataFromProfile(Model $profile): array
    {
        if ($profile instanceof Graduate && $profile->student) {
            return $this->dataFromProfile($profile->student) + [
                'photo_path' => $profile->photo_path ?: $profile->student->photo_path,
                'status' => $profile->status ?: 'active',
            ];
        }

        return [
            'last_name' => (string) ($profile->last_name ?? ''),
            'first_name' => (string) ($profile->first_name ?? ''),
            'middle_name' => $profile->middle_name ?? null,
            'birth_date' => $profile->birth_date?->toDateString(),
            'phone' => $profile->phone ?? null,
            'email' => $profile->email ?? null,
            'photo_path' => $profile->photo_path ?? null,
            'status' => $profile->status ?? (($profile->is_active ?? true) ? 'active' : 'inactive'),
        ];
    }

    public function linkExisting(bool $apply = false): array
    {
        $summary = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'profiles_scanned' => 0,
            'persons_created' => 0,
            'profiles_linked' => 0,
            'would_create' => 0,
            'would_link' => 0,
            'ambiguous_duplicates' => 0,
            'skipped' => 0,
            'ambiguous' => [],
        ];

        $profiles = $this->profilesToLink();

        DB::transaction(function () use ($profiles, $apply, &$summary): void {
            foreach ($profiles as $profile) {
                $summary['profiles_scanned']++;

                if ($profile->person_id) {
                    $summary['skipped']++;
                    continue;
                }

                if ($profile instanceof Graduate && $profile->student?->person_id) {
                    if ($apply) {
                        $this->linkProfile($profile, $profile->student->person);
                        $summary['profiles_linked']++;
                    } else {
                        $summary['would_link']++;
                    }
                    continue;
                }

                $data = $this->dataFromProfile($profile);
                $duplicates = $this->findPossibleDuplicates($data);

                if ($duplicates->count() > 1) {
                    $summary['ambiguous_duplicates']++;
                    $summary['ambiguous'][] = [
                        'profile_type' => class_basename($profile),
                        'profile_id' => $profile->id,
                        'name' => $this->fullName($data),
                        'candidate_ids' => $duplicates->pluck('id')->values()->all(),
                    ];
                    continue;
                }

                if ($duplicates->count() === 1) {
                    if ($apply) {
                        $this->linkProfile($profile, $duplicates->first());
                        $summary['profiles_linked']++;
                    } else {
                        $summary['would_link']++;
                    }
                    continue;
                }

                if ($apply) {
                    $person = $this->createPerson($data);
                    $this->linkProfile($profile, $person);
                    $summary['persons_created']++;
                    $summary['profiles_linked']++;
                } else {
                    $summary['would_create']++;
                }
            }
        });

        return $summary;
    }

    private function normalizePersonData(array $data): array
    {
        return [
            'uuid' => $this->blankToNull($data['uuid'] ?? null) ?: (string) Str::uuid(),
            'last_name' => trim((string) ($data['last_name'] ?? '')),
            'first_name' => trim((string) ($data['first_name'] ?? '')),
            'middle_name' => $this->blankToNull($data['middle_name'] ?? null),
            'birth_date' => $this->blankToNull($data['birth_date'] ?? null),
            'gender' => $this->blankToNull($data['gender'] ?? null),
            'citizenship' => $this->blankToNull($data['citizenship'] ?? null),
            'place_birth' => $this->blankToNull($data['place_birth'] ?? null),
            'phone' => $this->normalizePhone($data['phone'] ?? null),
            'email' => $this->normalizeEmail($data['email'] ?? null),
            'address' => $this->blankToNull($data['address'] ?? null),
            'photo_path' => $this->blankToNull($data['photo_path'] ?? null),
            'snils' => $this->normalizeDigits($data['snils'] ?? null),
            'snils_hash' => ($snils = $this->normalizeDigits($data['snils'] ?? null)) ? hash('sha256', $snils) : null,
            'inn' => $this->normalizeDigits($data['inn'] ?? null),
            'status' => $this->blankToNull($data['status'] ?? null) ?: 'active',
        ];
    }

    private function profilesToLink(): Collection
    {
        return collect()
            ->merge(Student::query()->with(['person', 'user'])->orderBy('id')->get())
            ->merge(Teacher::query()->with(['person', 'user'])->orderBy('id')->get())
            ->merge(ApplicantApplication::query()->legacy()->with('person')->orderBy('id')->get())
            ->merge(Graduate::query()->with(['person', 'student.person', 'student.user'])->orderBy('id')->get());
    }

    private function supportsPerson(Model $profile): bool
    {
        return $profile instanceof Student
            || $profile instanceof Teacher
            || $profile instanceof ApplicantApplication
            || $profile instanceof Graduate;
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

    private function normalizePhone(mixed $value): ?string
    {
        $digits = $this->normalizeDigits($value);

        return $digits ?: null;
    }

    private function fullName(array $data): string
    {
        return collect([$data['last_name'] ?? null, $data['first_name'] ?? null, $data['middle_name'] ?? null])->filter()->implode(' ');
    }
}
