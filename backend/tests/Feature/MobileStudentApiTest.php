<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\JournalAttendance;
use App\Models\JournalGrade;
use App\Models\JournalLesson;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileStudentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_mobile_student_cabinet_data(): void
    {
        $role = Role::create(['code' => 'student', 'name' => 'Студент']);
        // Кабинет закрыт правом `mobile.student.view` с `ARCH-001`, шага 3.
        // Раньше маршрут лежал в общей авторизованной группе, и право
        // спрашивал только маршрутизатор фронтенда.
        $role->permissions()->sync([Permission::query()->firstOrCreate(
            ['code' => 'mobile.student.view'],
            ['name' => 'Мобильный кабинет студента', 'module' => 'Mobile', 'system' => true, 'active' => true],
        )->id]);
        $user = User::factory()->create(['role_id' => $role->id, 'password' => Hash::make('password')]);
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $student = Student::create(['user_id' => $user->id, 'group_id' => $group->id, 'last_name' => 'Иванов', 'first_name' => 'Дмитрий', 'status' => 'active']);
        $teacher = Teacher::create(['last_name' => 'Смирнова', 'first_name' => 'Елена', 'is_active' => true]);
        $subject = Subject::create(['name' => 'Сольфеджио']);
        $classroom = Classroom::create(['number' => '201', 'building' => 'Главный корпус']);
        $lesson = ScheduleLesson::create([
            'group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'classroom_id' => $classroom->id,
            'lesson_date' => today(),
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'lesson_type' => 'lesson',
            'topic' => 'Повторение интервалов',
        ]);
        // Кабинет студента читает журнал, а не старые таблицы: оценку и
        // отметку ставит преподаватель в занятии журнала (16.08.2026).
        $journalLesson = JournalLesson::create([
            'group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'legacy_schedule_lesson_id' => $lesson->id,
            'lesson_date' => today(),
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'topic' => 'Повторение интервалов',
            'status' => JournalLesson::STATUS_IN_PROGRESS,
        ]);
        JournalGrade::create(['journal_lesson_id' => $journalLesson->id, 'student_id' => $student->id, 'value' => '5', 'marked_at' => now()]);
        JournalAttendance::create(['journal_lesson_id' => $journalLesson->id, 'student_id' => $student->id, 'status' => 'present', 'source' => 'manual', 'marked_at' => now()]);
        $identity = DigitalIdentity::create([
            'entity_type' => 'student',
            'entity_id' => $student->id,
            'token' => (string) Str::uuid(),
            'status' => 'active',
            'issued_at' => now(),
        ]);

        $this->withApiAuth($user)
            ->getJson('/api/mobile/student')
            ->assertOk()
            ->assertJsonPath('data.student.last_name', 'Иванов')
            ->assertJsonPath('data.student.group.name', 'ИСП-101')
            ->assertJsonPath('data.today_schedule.0.subject.name', 'Сольфеджио')
            ->assertJsonPath('data.grades.0.grade', '5')
            ->assertJsonPath('data.attendance_summary.present', 1)
            ->assertJsonPath('data.digital_identity.id', $identity->id)
            ->assertJsonPath('data.qr_refresh_seconds', 30)
            ->assertJsonStructure(['data' => ['qr_svg', 'qr_expires_at']]);

        $payload = $this->withApiAuth($user)->getJson('/api/mobile/student')->json('data');
        $this->assertStringContainsString('<svg', $payload['qr_svg']);
        $this->assertStringNotContainsString($identity->token, $payload['qr_svg']);

        ScheduleLesson::create([
            'group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'classroom_id' => $classroom->id,
            'lesson_date' => today()->addDay(),
            'starts_at' => '11:00',
            'ends_at' => '12:30',
            'lesson_type' => 'lesson',
        ]);

        $this->withApiAuth($user)
            ->getJson('/api/mobile/student?date='.today()->addDay()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.schedule_date', today()->addDay()->toDateString())
            ->assertJsonCount(1, 'data.today_schedule');
    }

    public function test_it_returns_placeholder_when_user_is_not_linked_to_student(): void
    {
        $user = $this->createApiUser(roleCode: 'admin');

        $this->withApiAuth($user)
            ->getJson('/api/mobile/student')
            ->assertOk()
            ->assertJsonPath('data.student', null)
            ->assertJsonPath('data.message', 'Текущий пользователь не связан с карточкой студента.');
    }
}
