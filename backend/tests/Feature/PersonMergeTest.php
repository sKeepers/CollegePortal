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
 * Слияние двух карточек одного человека.
 *
 * Дубли появляются законным путём: человека заводит загрузка контингента по
 * ФИО, а потом кадры заводят его же по СНИЛС. До 23.08.2026 свести их можно
 * было только запросом к базе, и владелец просил об этом дважды.
 *
 * Обратного хода у слияния нет, поэтому проверяется не только то, что оно
 * переносит записи, но и то, что оно **отказывается** в двух случаях, где
 * молчаливое слияние испортило бы данные.
 */
class PersonMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_plan_shows_what_moves_and_what_gets_filled(): void
    {
        $this->seed(RoleSeeder::class);
        $survivor = $this->createPerson('Оставшийся', ['phone' => null]);
        $absorbed = $this->createPerson('Присоединяемый', ['phone' => '+79990000000', 'snils' => '11122233344']);
        $this->attachEmployee($absorbed);

        $this->withApiAuth($this->userWith(['people.view', 'people.update']));

        $response = $this->postJson('/api/people/merge/preview', [
            'survivor_id' => $survivor->id,
            'absorbed_id' => $absorbed->id,
        ])->assertOk();

        $moves = collect($response->json('data.moves'));
        $fills = collect($response->json('data.fills'))->pluck('field');

        $this->assertTrue($moves->contains(fn ($move) => $move['code'] === 'Employee' && $move['count'] === 1));
        $this->assertTrue($fills->contains('phone'), 'Пустой телефон обязан дозаполниться из присоединяемой карточки.');
        $this->assertTrue($fills->contains('snils'));
        $this->assertSame([], $response->json('data.blockers'));
    }

    public function test_merging_moves_the_records_and_removes_the_duplicate(): void
    {
        $this->seed(RoleSeeder::class);
        $survivor = $this->createPerson('Оставшийся', ['phone' => null]);
        $absorbed = $this->createPerson('Присоединяемый', ['phone' => '+79990000000']);
        $employee = $this->attachEmployee($absorbed);
        $account = $this->attachAccount($absorbed);

        $this->withApiAuth($this->userWith(['people.view', 'people.update']));

        $this->postJson('/api/people/merge', [
            'survivor_id' => $survivor->id,
            'absorbed_id' => $absorbed->id,
        ])->assertOk();

        $this->assertSame($survivor->id, $employee->refresh()->person_id);
        $this->assertSame($survivor->id, $account->refresh()->person_id);
        $this->assertNull(Person::query()->find($absorbed->id), 'Присоединённая карточка обязана исчезнуть.');
        $this->assertSame('+79990000000', $survivor->refresh()->phone, 'Пустое поле дозаполняется из присоединяемой.');
        $this->assertSame('Оставшийся', $survivor->last_name, 'Непустое поле слияние переписывать не вправе.');
    }

    /**
     * Связь к профилю — `hasOne`, и вторая карточка молча перекрывает первую:
     * кабинет и журнал у неё пустые. Слияние, которое дало бы человеку два
     * студенческих профиля, обязано отказать, а не «как-нибудь» их сложить.
     */
    public function test_two_cards_of_the_same_kind_stop_the_merge(): void
    {
        $this->seed(RoleSeeder::class);
        $survivor = $this->createPerson('Оставшийся');
        $absorbed = $this->createPerson('Присоединяемый');
        $this->attachStudent($survivor, 'A-1');
        $this->attachStudent($absorbed, 'A-2');

        $this->withApiAuth($this->userWith(['people.view', 'people.update']));

        $this->postJson('/api/people/merge', [
            'survivor_id' => $survivor->id,
            'absorbed_id' => $absorbed->id,
        ])->assertStatus(422)->assertJsonPath('message', fn (string $message) => str_contains($message, 'карточка студента'));

        $this->assertNotNull(Person::query()->find($absorbed->id), 'Отказ обязан оставить обе карточки на месте.');
    }

    /**
     * Разные СНИЛС — сильный признак, что это разные люди: по нему человек
     * находится и к нему привязаны документы.
     */
    public function test_different_snils_stops_the_merge(): void
    {
        $this->seed(RoleSeeder::class);
        $survivor = $this->createPerson('Оставшийся', ['snils' => '11122233344']);
        $absorbed = $this->createPerson('Присоединяемый', ['snils' => '55566677788']);

        $this->withApiAuth($this->userWith(['people.view', 'people.update']));

        $this->postJson('/api/people/merge', [
            'survivor_id' => $survivor->id,
            'absorbed_id' => $absorbed->id,
        ])->assertStatus(422)->assertJsonPath('message', fn (string $message) => str_contains($message, 'СНИЛС'));

        $this->assertNotNull(Person::query()->find($absorbed->id));
    }

    public function test_a_card_cannot_be_merged_into_itself(): void
    {
        $this->seed(RoleSeeder::class);
        $person = $this->createPerson('Одинокий');

        $this->withApiAuth($this->userWith(['people.view', 'people.update']));

        $this->postJson('/api/people/merge', [
            'survivor_id' => $person->id,
            'absorbed_id' => $person->id,
        ])->assertStatus(422);
    }

    public function test_merging_needs_the_edit_permission(): void
    {
        $this->seed(RoleSeeder::class);
        $survivor = $this->createPerson('Оставшийся');
        $absorbed = $this->createPerson('Присоединяемый');

        $this->withApiAuth($this->userWith(['people.view']));

        $this->postJson('/api/people/merge', [
            'survivor_id' => $survivor->id,
            'absorbed_id' => $absorbed->id,
        ])->assertForbidden();
    }

    private function createPerson(string $lastName, array $extra = []): Person
    {
        return Person::query()->create([
            'last_name' => $lastName,
            'first_name' => 'Проверочный',
            'status' => 'active',
        ] + $extra);
    }

    private function attachEmployee(Person $person): Employee
    {
        return Employee::query()->create([
            'person_id' => $person->id,
            'employee_number' => 'EMP-'.$person->id,
            'status' => 'active',
            'employment_type' => 'main',
            'is_teacher' => false,
        ]);
    }

    private function attachStudent(Person $person, string $number): Student
    {
        // Группа обязательна: `students.group_id` не допускает пустого.
        $group = Group::firstOrCreate(
            ['name' => 'Проверочная группа'],
            ['specialty' => 'Проверочная специальность', 'course' => 1, 'year_start' => 2026],
        );

        return Student::query()->create([
            'person_id' => $person->id,
            'group_id' => $group->id,
            'last_name' => $person->last_name,
            'first_name' => $person->first_name,
            'status' => 'active',
            'student_number' => $number,
        ]);
    }

    private function attachAccount(Person $person): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['person_id' => $person->id])->save();

        return $user;
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['code' => 'person_merge_'.substr(md5(json_encode($permissions)), 0, 12)],
            ['name' => 'Слияние людей '.substr(md5(json_encode($permissions)), 0, 8), 'description' => 'Test role'],
        );

        foreach ($permissions as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => 'Test', 'description' => $code, 'system' => true, 'active' => true],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->sync([$role->id => ['is_primary' => true]]);
        $user->forceFill(['role_id' => $role->id])->save();

        return $user;
    }
}
