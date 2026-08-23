<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DormConductRecord;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Второй контур общежития: провинности и социальный паспорт.
 *
 * Проверяется то, ради чего он отделён: коменданту сюда хода нет, студенту тем
 * более, история не переписывается задним числом, а просмотр социального
 * паспорта оставляет след.
 */
class DormUpbringingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_warden_reaches_neither_conduct_nor_the_social_passport(): void
    {
        // У коменданта весь набор прав общежития, кроме этих двух.
        $this->withApiAuth($this->userWith([
            'dorm.rooms.view', 'dorm.rooms.manage',
            'dorm.placements.view', 'dorm.placements.manage',
            'dorm.payments.view', 'dorm.payments.manage',
            'dorm.incidents.view', 'dorm.absences.view', 'dorm.leaves.manage',
        ]));

        $this->getJson('/api/dorm/conduct')->assertForbidden();
        $this->postJson('/api/dorm/conduct', [])->assertForbidden();
        $this->getJson('/api/dorm/social')->assertForbidden();
        $this->postJson('/api/dorm/social', [])->assertForbidden();
    }

    public function test_a_student_reaches_nothing_of_this(): void
    {
        $this->withApiAuth($this->userWith(['view_own_data', 'mobile.student.view']));

        // Решение владельца 22.08.2026: студент своих провинностей не видит.
        // Отдельного запроса для него нет вовсе — проверяем, что и общий закрыт.
        $this->getJson('/api/dorm/conduct')->assertForbidden();
        $this->getJson('/api/dorm/social')->assertForbidden();
    }

    public function test_a_conduct_record_fades_after_a_year(): void
    {
        $this->withApiAuth($this->deputy());
        $student = $this->student();

        $this->postJson('/api/dorm/conduct', [
            'student_id' => $student->id,
            'happened_on' => '2026-09-10',
            'summary' => 'Нарушение режима',
        ])
            ->assertCreated()
            // Срок берётся из настройки, а не зашит: владелец назвал год.
            ->assertJsonPath('data.expires_on', '2027-09-10')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_the_author_may_fix_a_slip_within_a_day(): void
    {
        $this->withApiAuth($this->deputy());
        $record = $this->conduct();

        $this->patchJson("/api/dorm/conduct/{$record['id']}", ['summary' => 'Нарушение режима, исправлено'])
            ->assertOk()
            ->assertJsonPath('data.summary', 'Нарушение режима, исправлено');
    }

    public function test_after_a_day_only_an_amendment_is_possible(): void
    {
        $this->withApiAuth($this->deputy());
        $record = $this->conduct();

        DormConductRecord::query()->whereKey($record['id'])->update(['created_at' => now()->subDays(2)]);

        // История не переписывается задним числом, но ошибка исправима.
        $this->patchJson("/api/dorm/conduct/{$record['id']}", ['summary' => 'Поздняя правка'])
            ->assertStatus(422)
            ->assertJsonPath('errors.record.0', 'Записи больше суток — править её поздно. Допишите дополнение: история останется, а поправка встанет рядом.');

        $this->postJson("/api/dorm/conduct/{$record['id']}/amend", ['summary' => 'Разобрались: студент не при чём'])
            ->assertCreated();

        $rows = $this->getJson('/api/dorm/conduct')->assertOk()->json('data');

        // Дополнение стоит при исходной записи, а не отдельной строкой.
        $this->assertCount(1, $rows);
        $this->assertCount(1, $rows[0]['amendments']);
        $this->assertSame('Разобрались: студент не при чём', $rows[0]['amendments'][0]['summary']);
    }

    public function test_nobody_edits_a_record_signed_by_another(): void
    {
        $this->withApiAuth($this->deputy());
        $record = $this->conduct();

        $this->withApiAuth($this->deputy('second'));

        $this->patchJson("/api/dorm/conduct/{$record['id']}", ['summary' => 'Чужая правка'])
            ->assertStatus(422)
            ->assertJsonPath('errors.record.0', 'Правит только автор записи. Допишите дополнение — оно встанет рядом и не перепишет сказанного.');
    }

    public function test_reading_the_social_passport_leaves_a_trace(): void
    {
        $this->withApiAuth($this->deputy());

        $this->getJson('/api/dorm/social')->assertOk();

        // В остальном портале аудит пишет изменения. Здесь мало: спросят «кто
        // это смотрел», и ответ должен быть.
        $this->assertTrue(
            AuditLog::query()->where('module', 'dorm.social')->where('action', 'viewed')->exists(),
            'Просмотр социального паспорта не попал в аудит',
        );
    }

    private function conduct(): array
    {
        return $this->postJson('/api/dorm/conduct', [
            'student_id' => $this->student()->id,
            'happened_on' => '2026-09-10',
            'summary' => 'Нарушение режима',
        ])->assertCreated()->json('data');
    }

    private function student(string $lastName = 'Провинившийся'): Student
    {
        $existing = Student::query()->firstWhere('last_name', $lastName);

        if ($existing !== null) {
            return $existing;
        }

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

    private function deputy(string $suffix = 'first'): User
    {
        return $this->userWith([
            'dorm.conduct.view', 'dorm.conduct.manage',
            'dorm.social.view', 'dorm.social.manage',
        ], $suffix);
    }

    private function userWith(array $permissions, string $suffix = ''): User
    {
        $user = User::factory()->create();
        $code = 'upb_'.substr(md5(json_encode($permissions)), 0, 10);

        $role = Role::firstOrCreate(
            ['code' => $code],
            ['name' => 'Воспитание '.substr(md5($code), 0, 8), 'description' => 'Test role'],
        );

        foreach ($permissions as $permissionCode) {
            $permission = Permission::firstOrCreate(
                ['code' => $permissionCode],
                ['name' => $permissionCode, 'module' => 'Test', 'description' => $permissionCode, 'system' => true, 'active' => true],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);

        return $user;
    }
}
