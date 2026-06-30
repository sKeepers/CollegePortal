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

class GradeReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_builds_grade_report_by_group_and_subject(): void
    {
        $context = $this->createContext();
        [$student, $otherStudent] = $this->createStudents($context['group']);
        [$lesson, $otherLesson] = $this->createLessons($context);

        Grade::create([
            'schedule_lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'grade' => '5',
            'grade_type' => 'classwork',
        ]);
        Grade::create([
            'schedule_lesson_id' => $otherLesson->id,
            'student_id' => $student->id,
            'grade' => '4',
            'grade_type' => 'homework',
        ]);
        Grade::create([
            'schedule_lesson_id' => $lesson->id,
            'student_id' => $otherStudent->id,
            'grade' => 'зачет',
            'grade_type' => 'credit',
        ]);

        $this->getJson("/api/reports/grades-by-group?group_id={$context['group']->id}&subject_id={$context['subject']->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.students_count', 2)
            ->assertJsonPath('data.summary.lessons_count', 2)
            ->assertJsonPath('data.summary.grades_count', 3)
            ->assertJsonPath('data.summary.numeric_grades_count', 2)
            ->assertJsonPath('data.summary.average_grade', 4.5)
            ->assertJsonPath('data.students.0.average_grade', 4.5)
            ->assertJsonPath('data.students.1.average_grade', null);
    }

    public function test_it_exports_grade_report_to_csv(): void
    {
        $context = $this->createContext();
        [$student] = $this->createStudents($context['group']);
        [$lesson] = $this->createLessons($context);

        Grade::create([
            'schedule_lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'grade' => '5',
            'grade_type' => 'classwork',
        ]);

        $response = $this->get("/api/reports/grades-by-group/export?group_id={$context['group']->id}&subject_id={$context['subject']->id}");

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('student;group;subject;grades', $content);
        $this->assertStringContainsString('Иванов Дмитрий Сергеевич', $content);
        $this->assertStringContainsString('Сольфеджио', $content);
    }

    private function createContext(): array
    {
        return [
            'group' => Group::create([
                'name' => 'ИСП-101',
                'specialty' => 'Инструментальное исполнительство',
                'course' => 1,
                'year_start' => 2026,
            ]),
            'teacher' => Teacher::create([
                'last_name' => 'Смирнова',
                'first_name' => 'Елена',
            ]),
            'subject' => Subject::create([
                'name' => 'Сольфеджио',
                'code' => 'MUS-101',
            ]),
            'classroom' => Classroom::create([
                'number' => '201',
                'building' => 'Главный корпус',
            ]),
        ];
    }

    private function createStudents(Group $group): array
    {
        return [
            Student::create([
                'group_id' => $group->id,
                'last_name' => 'Иванов',
                'first_name' => 'Дмитрий',
                'middle_name' => 'Сергеевич',
                'status' => 'active',
            ]),
            Student::create([
                'group_id' => $group->id,
                'last_name' => 'Соколова',
                'first_name' => 'Анна',
                'middle_name' => 'Павловна',
                'status' => 'active',
            ]),
        ];
    }

    private function createLessons(array $context): array
    {
        return [
            ScheduleLesson::create([
                'group_id' => $context['group']->id,
                'teacher_id' => $context['teacher']->id,
                'subject_id' => $context['subject']->id,
                'classroom_id' => $context['classroom']->id,
                'lesson_date' => '2026-09-02',
                'starts_at' => '09:00',
                'ends_at' => '10:30',
                'lesson_type' => 'lesson',
            ]),
            ScheduleLesson::create([
                'group_id' => $context['group']->id,
                'teacher_id' => $context['teacher']->id,
                'subject_id' => $context['subject']->id,
                'classroom_id' => $context['classroom']->id,
                'lesson_date' => '2026-09-03',
                'starts_at' => '09:00',
                'ends_at' => '10:30',
                'lesson_type' => 'lesson',
            ]),
        ];
    }
}
