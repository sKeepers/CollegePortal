<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileTeacherApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Понедельник: границы недели в ответе становятся предсказуемыми.
        Carbon::setTestNow(Carbon::parse('2026-07-13 08:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cabinet_shows_only_own_lessons_and_ignores_teacher_id_from_request(): void
    {
        $fixture = $this->fixture();

        $payload = $this->withApiAuth($fixture['user'])
            ->getJson('/api/mobile/teacher')
            ->assertOk()
            ->assertJsonPath('data.teacher.id', $fixture['teacher']->id)
            ->assertJsonCount(2, 'data.lessons')
            ->json('data');

        $subjects = array_column(array_column($payload['lessons'], 'subject'), 'name');
        $this->assertSame(['Сольфеджио', 'Гармония'], $subjects);
        $this->assertNotContains('Хор', $subjects, 'Занятие другого преподавателя попало в кабинет.');

        // Подстановка чужого преподавателя в запросе ничего не меняет: параметр
        // не читается, преподаватель берётся из связанной карточки.
        $spoofed = $this->withApiAuth($fixture['user'])
            ->getJson('/api/mobile/teacher?teacher_id='.$fixture['otherTeacher']->id)
            ->assertOk()
            ->json('data');

        $this->assertSame(
            array_column($payload['lessons'], 'id'),
            array_column($spoofed['lessons'], 'id'),
        );
    }

    public function test_next_lesson_and_week_count_only_own_lessons(): void
    {
        $fixture = $this->fixture();

        $payload = $this->withApiAuth($fixture['user'])->getJson('/api/mobile/teacher')->assertOk()->json('data');

        $this->assertSame($fixture['morning']->id, $payload['next_lesson']);
        $this->assertCount(7, $payload['week']);
        $this->assertSame('2026-07-13', $payload['week'][0]['date']);
        $this->assertSame('2026-07-19', $payload['week'][6]['date']);
        // Понедельник — два своих занятия, вторник — одно; чужое занятие
        // понедельника в счёт не идёт.
        $this->assertSame(2, $payload['week'][0]['lessons']);
        $this->assertSame(1, $payload['week'][1]['lessons']);
        $this->assertSame(0, $payload['week'][2]['lessons']);
        $this->assertTrue($payload['week'][0]['is_selected']);

        $this->withApiAuth($fixture['user'])
            ->getJson('/api/mobile/teacher?date=2026-07-14')
            ->assertOk()
            ->assertJsonPath('data.schedule_date', '2026-07-14')
            ->assertJsonCount(1, 'data.lessons')
            ->assertJsonPath('data.lessons.0.subject.name', 'Полифония');
    }

    public function test_journal_state_follows_the_lesson_and_marks_are_counted(): void
    {
        $fixture = $this->fixture();

        $this->withApiAuth($fixture['user'])
            ->getJson('/api/mobile/teacher')
            ->assertOk()
            ->assertJsonPath('data.lessons.0.journal.lesson_id', null)
            ->assertJsonPath('data.lessons.0.journal.can_open', true)
            ->assertJsonPath('data.lessons.0.journal.can_mark_attendance', false)
            ->assertJsonPath('data.day_summary.journals_opened', 0);

        $lessonId = $this->withApiAuth($fixture['user'])
            ->postJson("/api/journal/from-legacy-schedule/{$fixture['morning']->id}/open")
            ->assertSuccessful()
            ->json('data.id');

        $this->withApiAuth($fixture['user'])
            ->getJson('/api/mobile/teacher')
            ->assertOk()
            ->assertJsonPath('data.lessons.0.journal.lesson_id', $lessonId)
            ->assertJsonPath('data.lessons.0.journal.students', 2)
            ->assertJsonPath('data.lessons.0.journal.marked', 0)
            ->assertJsonPath('data.lessons.0.journal.can_open', false)
            ->assertJsonPath('data.lessons.0.journal.can_mark_attendance', true)
            ->assertJsonPath('data.day_summary.journals_opened', 1);

        $this->withApiAuth($fixture['user'])
            ->putJson("/api/journal/lessons/{$lessonId}/attendance", [
                'attendance' => [['student_id' => $fixture['students'][0]->id, 'status' => 'late', 'minutes_late' => 7]],
            ])
            ->assertOk();

        $this->withApiAuth($fixture['user'])
            ->getJson('/api/mobile/teacher')
            ->assertOk()
            ->assertJsonPath('data.lessons.0.journal.marked', 1)
            ->assertJsonPath('data.day_summary.marked', 1)
            ->assertJsonPath('data.day_summary.students', 2);
    }

    public function test_signed_lesson_hides_marking_instead_of_offering_a_failing_button(): void
    {
        $fixture = $this->fixture(['journal.view', 'journal.edit', 'journal.attendance', 'journal.grades', 'journal.sign']);

        $lessonId = $this->withApiAuth($fixture['user'])
            ->postJson("/api/journal/from-legacy-schedule/{$fixture['morning']->id}/open")
            ->assertSuccessful()
            ->json('data.id');
        // Подпись требует заполненной темы — иначе журнал её не примет.
        $this->withApiAuth($fixture['user'])->putJson("/api/journal/lessons/{$lessonId}", ['topic' => 'Интервалы'])->assertOk();
        $this->withApiAuth($fixture['user'])->postJson("/api/journal/lessons/{$lessonId}/sign")->assertOk();

        $this->withApiAuth($fixture['user'])
            ->getJson('/api/mobile/teacher')
            ->assertOk()
            ->assertJsonPath('data.lessons.0.journal.is_signed', true)
            ->assertJsonPath('data.lessons.0.journal.can_mark_attendance', false)
            ->assertJsonPath('data.lessons.0.journal.can_set_grades', false);
    }

    public function test_teacher_without_journal_permissions_gets_no_actions(): void
    {
        $fixture = $this->fixture(['schedule.view']);

        $this->withApiAuth($fixture['user'])
            ->getJson('/api/mobile/teacher')
            ->assertOk()
            ->assertJsonCount(2, 'data.lessons')
            ->assertJsonPath('data.abilities.open_journal', false)
            ->assertJsonPath('data.abilities.mark_attendance', false)
            ->assertJsonPath('data.lessons.0.journal.can_open', false);
    }

    public function test_user_without_teacher_card_gets_empty_cabinet_not_forbidden(): void
    {
        $this->fixture();
        $stranger = $this->createApiUser(roleCode: 'employee');

        $this->withApiAuth($stranger)
            ->getJson('/api/mobile/teacher')
            ->assertOk()
            ->assertJsonPath('data.teacher', null)
            ->assertJsonPath('data.message', 'Текущий пользователь не связан с карточкой преподавателя.')
            ->assertJsonCount(0, 'data.lessons')
            ->assertJsonPath('data.day_summary.lessons', 0);
    }

    public function test_personal_pass_is_returned_without_exposing_the_token(): void
    {
        $fixture = $this->fixture();
        $identity = DigitalIdentity::create([
            'entity_type' => DigitalIdentity::ENTITY_TEACHER,
            'entity_id' => $fixture['teacher']->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);
        // Чужой пропуск выпущен позже: если отбор по владельцу когда-нибудь
        // пропадёт, «последний выпущенный» окажется чужим, и тест это увидит,
        // а не сойдётся случайно на одинаковом времени.
        DigitalIdentity::create([
            'entity_type' => DigitalIdentity::ENTITY_TEACHER,
            'entity_id' => $fixture['otherTeacher']->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now()->addMinute(),
        ]);

        $payload = $this->withApiAuth($fixture['user'])
            ->getJson('/api/mobile/teacher')
            ->assertOk()
            ->assertJsonPath('data.digital_identity.id', $identity->id)
            ->json('data');

        $this->assertStringContainsString('<svg', $payload['qr_svg']);
        $this->assertStringNotContainsString($identity->token, $payload['qr_svg']);
    }

    public function test_malformed_date_is_rejected(): void
    {
        $fixture = $this->fixture();

        $this->withApiAuth($fixture['user'])->getJson('/api/mobile/teacher?date=13.07.2026')->assertStatus(422);
    }

    /**
     * @param  list<string>  $permissions
     * @return array<string, mixed>
     */
    private function fixture(array $permissions = ['journal.view', 'journal.edit', 'journal.attendance', 'journal.grades']): array
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $solfeggio = Subject::create(['name' => 'Сольфеджио']);
        $harmony = Subject::create(['name' => 'Гармония']);
        $polyphony = Subject::create(['name' => 'Полифония']);
        $choir = Subject::create(['name' => 'Хор']);
        $classroom = Classroom::create(['number' => '201', 'building' => 'Главный корпус']);

        $user = User::factory()->create(['is_active' => true, 'role_id' => $this->roleWithPermissions('teacher', $permissions)->id]);
        $teacher = Teacher::create(['user_id' => $user->id, 'last_name' => 'Смирнова', 'first_name' => 'Елена', 'is_active' => true]);

        $otherUser = User::factory()->create(['is_active' => true]);
        $otherTeacher = Teacher::create(['user_id' => $otherUser->id, 'last_name' => 'Петров', 'first_name' => 'Игорь', 'is_active' => true]);

        $students = [
            Student::create(['group_id' => $group->id, 'last_name' => 'Абрамов', 'first_name' => 'Пётр', 'status' => 'active']),
            Student::create(['group_id' => $group->id, 'last_name' => 'Белова', 'first_name' => 'Анна', 'status' => 'active']),
        ];

        $morning = $this->lesson($group, $teacher, $solfeggio, $classroom, '2026-07-13', '09:00', '10:30');
        $this->lesson($group, $teacher, $harmony, $classroom, '2026-07-13', '11:00', '12:30');
        $this->lesson($group, $teacher, $polyphony, $classroom, '2026-07-14', '09:00', '10:30');
        // Чужое занятие в тот же день и в той же группе.
        $this->lesson($group, $otherTeacher, $choir, $classroom, '2026-07-13', '13:00', '14:30');

        return [
            'user' => $user,
            'teacher' => $teacher,
            'otherTeacher' => $otherTeacher,
            'students' => $students,
            'morning' => $morning,
            'group' => $group,
        ];
    }

    private function lesson(Group $group, Teacher $teacher, Subject $subject, Classroom $classroom, string $date, string $from, string $to): ScheduleLesson
    {
        return ScheduleLesson::create([
            'group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'classroom_id' => $classroom->id,
            'lesson_date' => $date,
            'starts_at' => $from,
            'ends_at' => $to,
            'lesson_type' => 'lesson',
        ]);
    }

    /** @param  list<string>  $permissions */
    private function roleWithPermissions(string $code, array $permissions): Role
    {
        $role = Role::query()->firstOrCreate(['code' => $code], ['name' => ucfirst($code)]);
        $ids = collect($permissions)->map(fn (string $permission) => Permission::query()->firstOrCreate(
            ['code' => $permission],
            ['name' => $permission, 'module' => 'Journal', 'description' => null, 'system' => true, 'active' => true],
        )->id);
        $role->permissions()->sync($ids);

        return $role;
    }
}
