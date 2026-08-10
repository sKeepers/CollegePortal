<?php

namespace Tests\Feature;

use App\Models\DeletionRequest;
use App\Models\Group;
use App\Models\JournalEditRequest;
use App\Models\JournalLesson;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_cabinet_returns_counts_and_pending_work(): void
    {
        $world = $this->world();

        $this->withApiAuth($world['admin'])
            ->getJson('/api/mobile/admin')
            ->assertOk()
            ->assertJsonPath('data.counts.students', 2)
            ->assertJsonPath('data.counts.teachers', 1)
            ->assertJsonPath('data.counts.groups', 1)
            ->assertJsonPath('data.pending.journal_edit_requests', 1)
            ->assertJsonPath('data.pending.deletion_requests', 1)
            ->assertJsonPath('data.abilities.review_journal_requests', true)
            ->assertJsonPath('data.abilities.review_deletion_requests', true)
            ->assertJsonStructure(['data' => ['today' => ['teachers', 'students']]]);
    }

    public function test_counters_are_hidden_from_a_user_who_cannot_act_on_them(): void
    {
        $this->world();

        // Раздел открыт, но решать по запросам этот человек не может — значит и
        // счётчика он не видит: число, по которому нельзя ничего сделать, это
        // та же неработающая кнопка.
        $limited = User::factory()->create([
            'is_active' => true,
            'role_id' => $this->roleWithPermissions('duty', ['mobile.admin.view'])->id,
        ]);

        $payload = $this->withApiAuth($limited)
            ->getJson('/api/mobile/admin')
            ->assertOk()
            ->assertJsonPath('data.abilities.review_journal_requests', false)
            ->assertJsonPath('data.abilities.review_deletion_requests', false)
            ->assertJsonPath('data.today', null)
            ->json('data');

        $this->assertSame([], $payload['pending']);
    }

    public function test_section_permission_is_required(): void
    {
        $this->world();
        $stranger = User::factory()->create([
            'is_active' => true,
            'role_id' => $this->roleWithPermissions('employee', ['dashboard.view'])->id,
        ]);

        $this->withApiAuth($stranger)->getJson('/api/mobile/admin')->assertForbidden();
    }

    public function test_pending_count_follows_the_decision_made_from_the_phone(): void
    {
        $world = $this->world();

        $this->withApiAuth($world['admin'])
            ->getJson('/api/mobile/admin')
            ->assertOk()
            ->assertJsonPath('data.pending.journal_edit_requests', 1);

        // Решение принимается существующим маршрутом журнала — своего пути
        // кабинет не заводит. Проверяется именно то, что телефон закрывает
        // запрос целиком, а не только показывает его.
        $this->withApiAuth($world['admin'])
            ->postJson("/api/journal/edit-requests/{$world['editRequest']->id}/review", [
                'approved' => true,
                'comment' => 'Разрешено с телефона',
            ])
            ->assertOk();

        $this->withApiAuth($world['admin'])
            ->getJson('/api/mobile/admin')
            ->assertOk()
            ->assertJsonPath('data.pending.journal_edit_requests', 0);
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $admin = $this->createApiUser(roleCode: 'admin');

        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $teacher = Teacher::create(['last_name' => 'Смирнова', 'first_name' => 'Елена', 'is_active' => true]);
        $subject = Subject::create(['name' => 'Сольфеджио']);
        $students = [
            Student::create(['group_id' => $group->id, 'last_name' => 'Абрамов', 'first_name' => 'Пётр', 'status' => 'active']),
            Student::create(['group_id' => $group->id, 'last_name' => 'Белова', 'first_name' => 'Анна', 'status' => 'active']),
        ];

        $lesson = JournalLesson::create([
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'lesson_date' => today(),
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'status' => JournalLesson::STATUS_SIGNED,
            'topic' => 'Интервалы',
            'signed_at' => now(),
        ]);

        $editRequest = JournalEditRequest::create([
            'journal_lesson_id' => $lesson->id,
            'requested_by' => $admin->id,
            'reason' => 'Исправить оценку',
            'status' => JournalEditRequest::STATUS_PENDING,
        ]);

        DeletionRequest::create([
            'subject_type' => Student::class,
            'subject_id' => $students[0]->id,
            'requested_by' => $admin->id,
            'reason' => 'Отчислен',
            'status' => DeletionRequest::STATUS_PENDING,
        ]);

        return ['admin' => $admin, 'group' => $group, 'teacher' => $teacher, 'lesson' => $lesson, 'editRequest' => $editRequest, 'students' => $students];
    }

    /** @param  list<string>  $permissions */
    private function roleWithPermissions(string $code, array $permissions): Role
    {
        $role = Role::query()->firstOrCreate(['code' => $code], ['name' => ucfirst($code)]);
        $ids = collect($permissions)->map(fn (string $permission) => Permission::query()->firstOrCreate(
            ['code' => $permission],
            ['name' => $permission, 'module' => 'Mobile', 'description' => null, 'system' => true, 'active' => true],
        )->id);
        $role->permissions()->sync($ids);

        return $role;
    }
}
