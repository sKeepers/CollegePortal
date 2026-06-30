<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Group;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_lists_attendance(): void
    {
        $context = $this->createContext();
        $attendance = Attendance::create([
            'schedule_lesson_id' => $context['lesson']->id,
            'student_id' => $context['student']->id,
            'status' => 'present',
        ]);

        $this->getJson("/api/attendance?schedule_lesson_id={$context['lesson']->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $attendance->id)
            ->assertJsonPath('data.0.status', 'present');
    }

    public function test_it_creates_attendance(): void
    {
        $context = $this->createContext();

        $this->postJson('/api/attendance', [
            'schedule_lesson_id' => $context['lesson']->id,
            'student_id' => $context['student']->id,
            'status' => 'late',
            'comment' => 'Came 10 minutes late.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'late');

        $this->assertDatabaseHas('attendance', [
            'schedule_lesson_id' => $context['lesson']->id,
            'student_id' => $context['student']->id,
            'status' => 'late',
        ]);
    }

    public function test_it_rejects_duplicate_attendance_for_same_student_and_lesson(): void
    {
        $context = $this->createContext();
        Attendance::create([
            'schedule_lesson_id' => $context['lesson']->id,
            'student_id' => $context['student']->id,
            'status' => 'present',
        ]);

        $this->postJson('/api/attendance', [
            'schedule_lesson_id' => $context['lesson']->id,
            'student_id' => $context['student']->id,
            'status' => 'absent',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_id');
    }

    public function test_it_rejects_student_from_another_group(): void
    {
        $context = $this->createContext();
        $otherGroup = Group::create([
            'name' => 'D-101',
            'specialty' => 'Design',
            'course' => 1,
            'year_start' => 2026,
        ]);
        $otherStudent = Student::create([
            'group_id' => $otherGroup->id,
            'last_name' => 'Sokolova',
            'first_name' => 'Anna',
            'status' => 'active',
        ]);

        $this->postJson('/api/attendance', [
            'schedule_lesson_id' => $context['lesson']->id,
            'student_id' => $otherStudent->id,
            'status' => 'present',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_id');
    }

    public function test_it_updates_attendance(): void
    {
        $context = $this->createContext();
        $attendance = Attendance::create([
            'schedule_lesson_id' => $context['lesson']->id,
            'student_id' => $context['student']->id,
            'status' => 'present',
        ]);

        $this->patchJson("/api/attendance/{$attendance->id}", [
            'status' => 'excused',
            'comment' => 'Medical document provided.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'excused');
    }

    public function test_it_deletes_attendance(): void
    {
        $context = $this->createContext();
        $attendance = Attendance::create([
            'schedule_lesson_id' => $context['lesson']->id,
            'student_id' => $context['student']->id,
            'status' => 'present',
        ]);

        $this->deleteJson("/api/attendance/{$attendance->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('attendance', ['id' => $attendance->id]);
    }

    private function createContext(): array
    {
        $group = Group::create([
            'name' => 'M-101',
            'specialty' => 'Instrumental Performance',
            'course' => 1,
            'year_start' => 2026,
        ]);
        $teacher = Teacher::create(['last_name' => 'Petrov', 'first_name' => 'Alexey']);
        $subject = Subject::create(['name' => 'Music Theory', 'code' => 'MUS-101']);
        $classroom = Classroom::create(['number' => '201', 'building' => 'Main']);
        $student = Student::create([
            'group_id' => $group->id,
            'last_name' => 'Ivanov',
            'first_name' => 'Dmitry',
            'status' => 'active',
        ]);
        $lesson = ScheduleLesson::create([
            'group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'classroom_id' => $classroom->id,
            'lesson_date' => '2026-09-02',
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'lesson_type' => 'lesson',
        ]);

        return compact('group', 'teacher', 'subject', 'classroom', 'student', 'lesson');
    }
}
