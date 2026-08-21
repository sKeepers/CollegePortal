<?php

namespace Tests\Feature;

use App\Models\DigitalIdentity;
use App\Models\Employee;
use App\Models\Graduate;
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
 * Удаление карточки человека.
 *
 * Раньше человек не удалялся никак: двухшаговое удаление знало только
 * профильные карточки, и удалённый студент исчезал, а человек оставался в
 * разделе «Люди» навсегда.
 *
 * Удалять его в одиночку тоже нельзя. Внешние ключи у студента, преподавателя
 * и выпускника обнуляющие: строка ушла бы молча, оставив карточки без ФИО —
 * ровно та поломка, из-за которой на боевом портале были «?» вместо имени.
 * Поэтому связанные записи снимаются явно и показываются до пометки, а те, за
 * которыми стоят поданные документы и дипломы, удалению мешают.
 */
class PersonDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_related_records_are_listed_before_the_card_is_marked(): void
    {
        $this->seed(RoleSeeder::class);
        $person = $this->createPerson('Ошибочный');
        $this->attachEmployee($person);
        $this->attachAccount($person);
        $this->attachPass($person);

        $this->withApiAuth($this->userWith(['people.view', 'trash.request']));

        $response = $this->getJson('/api/deletion-requests/dependents?subject_type=person&subject_id='.$person->id)
            ->assertOk();

        $cascade = collect($response->json('data.cascade'))->pluck('count', 'relation');

        $this->assertSame(1, $cascade['employees']);
        $this->assertSame(1, $cascade['users']);
        $this->assertSame(1, $cascade['digitalIdentities']);
        $this->assertSame([], $response->json('data.blockers'));
    }

    public function test_a_person_with_a_graduation_record_cannot_be_marked(): void
    {
        $this->seed(RoleSeeder::class);
        $person = $this->createPerson('Выпускник');

        $group = Group::query()->create(['name' => 'Г-выпуск', 'specialty' => 'Проверка', 'course' => 4, 'year_start' => 2023]);
        $student = Student::query()->create([
            'group_id' => $group->id,
            'person_id' => $person->id,
            'last_name' => 'Выпускник',
            'first_name' => 'Проверочный',
            'status' => 'active',
        ]);
        Graduate::query()->create([
            'person_id' => $person->id,
            'student_id' => $student->id,
            'group_id' => $group->id,
            'graduation_year' => 2026,
            'status' => 'ready',
        ]);

        $this->withApiAuth($this->userWith(['people.view', 'trash.request']));

        $this->postJson('/api/deletion-requests', [
            'subject_type' => 'person',
            'subject_id' => $person->id,
            'reason' => 'Карточка заведена по ошибке.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.subject_id.0', 'Карточку нельзя пометить на удаление, пока за человеком числится: '
                .'запись выпускника — 1. Эти записи удаляются отдельно, в своём разделе.');

        // Ни человек, ни диплом от отказа не пострадали.
        $this->assertNotNull(Person::query()->find($person->id));
        $this->assertNotNull(Student::query()->find($student->id));
    }

    public function test_approving_takes_down_the_cards_the_account_and_the_pass(): void
    {
        $this->seed(RoleSeeder::class);
        $person = $this->createPerson('Ошибочный');
        $employee = $this->attachEmployee($person);
        $account = $this->attachAccount($person);
        $pass = $this->attachPass($person);

        $request = $this->markForDeletion($person);

        $this->withApiAuth($this->userWith(['trash.manage']));
        $this->postJson("/api/deletion-requests/{$request['id']}/approve")->assertOk();

        // Человек ушёл в корзину и больше не показывается в разделе «Люди».
        $this->assertNull(Person::query()->find($person->id));
        $this->assertNotNull(Person::withTrashed()->find($person->id));

        $this->assertNull(Employee::query()->find($employee->id));
        $this->assertNotNull(Employee::withTrashed()->find($employee->id));

        // Вход выключен, но учётная запись цела: пока человек в корзине, решение
        // можно отменить целиком.
        $this->assertFalse((bool) $account->fresh()->is_active);
        $this->assertSame(DigitalIdentity::STATUS_REVOKED, $pass->fresh()->status);
    }

    public function test_restoring_brings_everything_back(): void
    {
        $this->seed(RoleSeeder::class);
        $person = $this->createPerson('Ошибочный');
        $employee = $this->attachEmployee($person);
        $account = $this->attachAccount($person);
        $pass = $this->attachPass($person);

        $request = $this->markForDeletion($person);
        $this->withApiAuth($this->userWith(['trash.manage']));
        $this->postJson("/api/deletion-requests/{$request['id']}/approve")->assertOk();

        $this->postJson("/api/trash/person/{$person->id}/restore")->assertOk();

        $this->assertNotNull(Person::query()->find($person->id));
        $this->assertNotNull(Employee::query()->find($employee->id));
        $this->assertTrue((bool) $account->fresh()->is_active);
        $this->assertSame(DigitalIdentity::STATUS_ACTIVE, $pass->fresh()->status);
    }

    public function test_purging_removes_the_person_and_everything_attached(): void
    {
        $this->seed(RoleSeeder::class);
        $person = $this->createPerson('Ошибочный');
        $employee = $this->attachEmployee($person);
        $account = $this->attachAccount($person);
        $pass = $this->attachPass($person);

        $request = $this->markForDeletion($person);
        $this->withApiAuth($this->userWith(['trash.manage']));
        $this->postJson("/api/deletion-requests/{$request['id']}/approve")->assertOk();
        $this->deleteJson("/api/trash/person/{$person->id}")->assertOk();

        $this->assertNull(Person::withTrashed()->find($person->id));
        $this->assertNull(Employee::withTrashed()->find($employee->id));
        $this->assertDatabaseMissing('users', ['id' => $account->id]);
        $this->assertDatabaseMissing('digital_identities', ['id' => $pass->id]);
    }

    /** @return array<string, mixed> */
    private function markForDeletion(Person $person): array
    {
        $this->withApiAuth($this->userWith(['people.view', 'trash.request']));

        return $this->postJson('/api/deletion-requests', [
            'subject_type' => 'person',
            'subject_id' => $person->id,
            'reason' => 'Карточка заведена дважды, эта лишняя.',
        ])->assertCreated()->json('data');
    }

    private function createPerson(string $lastName): Person
    {
        return Person::query()->create([
            'last_name' => $lastName,
            'first_name' => 'Проверочный',
            'status' => 'active',
        ]);
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

    private function attachAccount(Person $person): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['person_id' => $person->id])->save();

        return $user;
    }

    private function attachPass(Person $person): DigitalIdentity
    {
        // С 21.08.2026 пропуск выдаётся сам при заведении карточки, поэтому
        // здесь берём выданный: заводить второй значило бы проверять состояние,
        // которого в жизни не бывает — пропуск принадлежит человеку, и он один.
        $issued = DigitalIdentity::query()->where('person_id', $person->id)->first();

        if ($issued !== null) {
            return $issued;
        }

        return DigitalIdentity::query()->create([
            'person_id' => $person->id,
            'entity_type' => DigitalIdentity::ENTITY_EMPLOYEE,
            'entity_id' => $person->id,
            'token' => 'test-'.$person->id,
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['code' => 'person_trash_'.substr(md5(json_encode($permissions)), 0, 12)],
            ['name' => 'Удаление человека '.substr(md5(json_encode($permissions)), 0, 8), 'description' => 'Test role'],
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
