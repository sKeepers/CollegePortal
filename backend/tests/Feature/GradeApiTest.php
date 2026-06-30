<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Grade;
use App\Models\Group;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_lists_grades(): void
    {
        $context = $this->createContext();
        $grade = Grade::create([
            'schedule_lesson_id' => $context['lesson']->id,
            'student_id' => $context['student']->id,
            'grade' => '5',
            'grade_type' => 'classwork',
        ]);

        $this->getJson("/api/grades?schedule_lesson_id={$context['lesson']->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $grade->id)
            ->assertJsonPath('data.0.grade', '5');
    }

    public function test_it_creates_grade(): void
    {
        $context = $this->createContext();

        $this->postJson('/api/grades', [
            'schedule_lesson_id' => $context['lesson']->id,
            'student_id' => $context['student']->id,
            'grade' => '5',
            'grade_type' => 'classwork',
            'comment' => 'Good answer.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.grade', '5');

        $this->assertDatabaseHas('grades', [
            'schedule_lesson_id' => $context['lesson']->id,
            'student_id' => $context['student']->id,
            'grade' => '5',
        ]);
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

        $this->postJson('/api/grades', [
            'schedule_lesson_id' => $context['lesson']->id,
            'student_id' => $otherStudent->id,
            'grade' => '4',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_id');
    }

    public function test_it_updates_grade(): void
    {
        $context = $this->createContext();
        $grade = Grade::create([
            'schedule_lesson_id' => $context['lesson']->id,
            'student_id' => $context['student']->id,
            'grade' => '4',
        ]);

        $this->patchJson("/api/grades/{$grade->id}", [
            'grade' => '5',
            'comment' => 'Corrected after oral answer.',
        ])
            ->assertOk()
            ->assertJsonPath('data.grade', '5');
    }

    public function test_it_deletes_grade(): void
    {
        $context = $this->createContext();
        $grade = Grade::create([
            'schedule_lesson_id' => $context['lesson']->id,
            'student_id' => $context['student']->id,
            'grade' => '5',
        ]);

        $this->deleteJson("/api/grades/{$grade->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('grades', ['id' => $grade->id]);
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
