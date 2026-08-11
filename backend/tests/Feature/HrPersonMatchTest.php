<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `HR-002`: кадровику есть из чего выбрать, когда совпадений несколько.
 *
 * До 11.08.2026 портал отвечал «Выберите существующую запись вручную», а список
 * в форме наполнялся реестром людей под `people.view`, которого у кадров нет
 * намеренно. Требование было невыполнимо: завести сотрудника становилось нельзя
 * вовсе.
 */
class HrPersonMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_clerk_sees_the_people_their_own_input_found(): void
    {
        $this->seed(RoleSeeder::class);
        [$mother, $daughter] = $this->twoPeopleOnOneFamilyPhone();

        $this->withApiAuth($this->hrUser());

        $response = $this->postJson('/api/hr/person-matches', [
            'last_name' => 'Ковалёва',
            'first_name' => 'Анна',
            // Записан цифрами, введён как читают люди: поиск сравнивает цифры.
            'phone' => '+7 999 000-11-22',
        ])->assertOk();

        $matches = collect($response->json('data.matches'))->keyBy('id');

        $this->assertCount(2, $matches, 'ожидались обе записи с общим телефоном');
        // Чем совпало — половина ответа: телефон читается иначе, чем СНИЛС.
        $this->assertSame(['phone'], $matches[$mother->id]['matched_by']);
        // Кем человек уже является — это и отличает одну запись от другой.
        $this->assertContains('сотрудник', $matches[$mother->id]['roles']);
        $this->assertContains('студент', $matches[$daughter->id]['roles']);
    }

    /**
     * Узкий взгляд — значит узкий. Совпавшее значение кадровик и так знает, он
     * его только что ввёл; всё остальное к выбору отношения не имеет.
     */
    public function test_the_answer_carries_nothing_beyond_the_choice(): void
    {
        $this->seed(RoleSeeder::class);
        $this->twoPeopleOnOneFamilyPhone();

        $this->withApiAuth($this->hrUser());

        $body = $this->postJson('/api/hr/person-matches', ['phone' => '+79990001122'])
            ->assertOk()
            ->getContent();

        foreach (['Тихая улица', '123456789012', 'address', 'inn', 'snils', 'email'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "в ответе не должно быть {$forbidden}");
        }
    }

    public function test_the_registry_stays_closed_to_those_without_the_permission(): void
    {
        $this->seed(RoleSeeder::class);

        $this->withApiAuth($this->userWith(['hr.employees.view', 'hr.employees.create']));
        $this->postJson('/api/hr/person-matches', ['phone' => '+79990001122'])->assertForbidden();

        // Реестр людей кадрам по-прежнему закрыт: узкий ответ его не открывает.
        $this->withApiAuth($this->hrUser());
        $this->getJson('/api/people')->assertForbidden();
    }

    /**
     * Тупик, ради которого всё делалось: без выбора карточку завести нельзя, а с
     * выбором — можно, и второго человека при этом не появляется.
     */
    public function test_the_clerk_gets_past_the_ambiguity_by_choosing(): void
    {
        $this->seed(RoleSeeder::class);
        [$mother] = $this->twoPeopleOnOneFamilyPhone();
        $peopleBefore = Person::query()->count();

        $this->withApiAuth($this->hrUser());

        $payload = [
            'last_name' => 'Ковалёва',
            'first_name' => 'Анна',
            'phone' => '79990001122',
            'employee_number' => 'T-900',
            'hired_at' => '2026-09-01',
        ];

        $this->postJson('/api/employees', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('person_id');

        $this->postJson('/api/employees', $payload + ['person_id' => $mother->id])
            ->assertSuccessful()
            ->assertJsonPath('data.person.id', $mother->id);

        $this->assertSame($peopleBefore, Person::query()->count(), 'выбор существующего человека не должен заводить нового');
    }

    /** Дата рождения доезжает до человека и на заведении, и на правке карточки. */
    public function test_the_birth_date_reaches_the_person(): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->hrUser());

        $created = $this->postJson('/api/employees', [
            'last_name' => 'Северов',
            'first_name' => 'Пётр',
            'birth_date' => '1980-04-12',
            'employee_number' => 'T-901',
        ])->assertSuccessful();

        $employee = Employee::query()->findOrFail($created->json('data.id'));
        $this->assertSame('1980-04-12', $employee->person->birth_date?->toDateString());

        $this->patchJson("/api/employees/{$employee->id}", ['birth_date' => '1980-04-13'])->assertOk();
        $this->assertSame('1980-04-13', $employee->person->fresh()->birth_date?->toDateString());
    }

    /**
     * Мать и дочь на одном семейном телефоне — обычное дело в колледже, и
     * именно этот случай упирался в тупик.
     *
     * @return array{0: Person, 1: Person}
     */
    private function twoPeopleOnOneFamilyPhone(): array
    {
        $mother = Person::create([
            'last_name' => 'Ковалёва', 'first_name' => 'Анна', 'middle_name' => 'Сергеевна',
            'birth_date' => '1979-02-11', 'phone' => '79990001122',
            'address' => 'Тихая улица, 4', 'inn' => '123456789012', 'status' => 'active',
        ]);
        Employee::create([
            'person_id' => $mother->id, 'employee_number' => 'T-800',
            'status' => 'active', 'employment_type' => 'full_time', 'hired_at' => '2020-09-01',
        ]);

        $daughter = Person::create([
            'last_name' => 'Ковалёва', 'first_name' => 'Анна', 'middle_name' => 'Ивановна',
            'birth_date' => '2006-05-30', 'phone' => '79990001122', 'status' => 'active',
        ]);
        $group = Group::create(['name' => 'ИСП-201', 'specialty' => 'ИИ', 'course' => 2, 'year_start' => 2024]);
        Student::create([
            'person_id' => $daughter->id, 'group_id' => $group->id,
            'last_name' => 'Ковалёва', 'first_name' => 'Анна',
            'middle_name' => 'Ивановна', 'status' => 'active',
        ]);

        return [$mother, $daughter];
    }

    private function hrUser(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', 'hr')->firstOrFail()->id);

        return $user;
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['code' => 'hr_match_'.substr(md5(json_encode($permissions)), 0, 10)],
            ['name' => 'HR-002 '.substr(md5(json_encode($permissions)), 0, 8), 'description' => 'Test role'],
        );

        foreach ($permissions as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => 'Test', 'description' => $code, 'system' => true, 'active' => true],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);

        return $user;
    }
}
