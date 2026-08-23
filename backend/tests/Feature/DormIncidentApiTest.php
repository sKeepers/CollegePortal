<?php

namespace Tests\Feature;

use App\Models\DormIncident;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Происшествия в общежитии.
 *
 * Единственная часть общежития, общая для двух контуров: ведут обе роли.
 * Запись делается по горячим следам, поэтому обязательны только время и одна
 * строка — всё остальное дописывается потом.
 */
class DormIncidentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_incident_is_recorded_with_time_and_one_line(): void
    {
        $this->withApiAuth($this->keeper());

        $this->postJson('/api/dorm/incidents', [
            'happened_at' => '2026-09-02 23:40:00',
            'summary' => 'Потоп на втором этаже',
        ])
            ->assertCreated()
            ->assertJsonPath('data.summary', 'Потоп на втором этаже');

        // Потребуй участников и меры сразу — запись не появится вовсе.
        $this->assertSame(1, DormIncident::query()->count());
    }

    public function test_details_participants_and_measures_are_added_later(): void
    {
        $this->withApiAuth($this->keeper());
        $student = $this->student();

        $incident = $this->postJson('/api/dorm/incidents', [
            'happened_at' => '2026-09-02 23:40:00',
            'summary' => 'Драка в коридоре',
        ])->assertCreated()->json('data');

        $this->patchJson("/api/dorm/incidents/{$incident['id']}", [
            'happened_at' => '2026-09-02 23:40:00',
            'summary' => 'Драка в коридоре',
            'measures' => 'Разобрались, вызваны родители',
            'participants' => [$student->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.measures', 'Разобрались, вызваны родители')
            ->assertJsonPath('data.participants.0.id', $student->id);
    }

    public function test_the_participant_list_is_replaced_whole(): void
    {
        $this->withApiAuth($this->keeper());
        $first = $this->student('Первый');
        $second = $this->student('Второй');

        $incident = $this->postJson('/api/dorm/incidents', [
            'happened_at' => '2026-09-02 21:00:00',
            'summary' => 'Кража',
            'participants' => [$first->id, $second->id],
        ])->assertCreated()->json('data');

        // Проще снять галочку, чем искать, кого убрать по одному.
        $this->patchJson("/api/dorm/incidents/{$incident['id']}", [
            'happened_at' => '2026-09-02 21:00:00',
            'summary' => 'Кража',
            'participants' => [$second->id],
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.participants')
            ->assertJsonPath('data.participants.0.id', $second->id);
    }

    public function test_both_roles_of_the_dormitory_reach_incidents(): void
    {
        // Единственная общая часть двух контуров: комендант живёт этим по
        // должности, заместитель разбирает последствия.
        $this->withApiAuth($this->userWith(['dorm.incidents.view'], 'deputy'));
        $this->getJson('/api/dorm/incidents')->assertOk();

        $this->withApiAuth($this->userWith(['dorm.rooms.view'], 'outsider'));
        $this->getJson('/api/dorm/incidents')->assertForbidden();
    }

    private function student(string $lastName = 'Участников'): Student
    {
        $group = Group::query()->firstWhere('name', 'ИСП-101') ?? Group::create([
            'name' => 'ИСП-101',
            'specialty' => 'Инструментальное исполнительство',
            'year_start' => 2026,
        ]);

        return Student::create([
            'group_id' => $group->id,
            'last_name' => $lastName,
            'first_name' => 'Иван',
            'status' => 'active',
        ]);
    }

    private function keeper(): User
    {
        return $this->userWith(['dorm.incidents.view', 'dorm.incidents.manage'], 'keeper');
    }

    private function userWith(array $permissions, string $suffix): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['code' => 'inc_'.$suffix],
            ['name' => 'Происшествия '.$suffix, 'description' => 'Test role'],
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
