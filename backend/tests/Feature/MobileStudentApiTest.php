<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\DigitalIdentity;
use App\Models\Grade;
use App\Models\Group;
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
        Grade::create(['schedule_lesson_id' => $lesson->id, 'student_id' => $student->id, 'grade' => '5', 'grade_type' => 'classwork']);
        Attendance::create(['schedule_lesson_id' => $lesson->id, 'student_id' => $student->id, 'status' => 'present']);
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
