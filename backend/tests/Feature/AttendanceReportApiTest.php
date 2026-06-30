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

class AttendanceReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_builds_attendance_report_by_group(): void
    {
        $context = $this->createContext();
        [$student, $otherStudent] = $this->createStudents($context['group']);
        [$lesson, $otherLesson] = $this->createLessons($context);

        Attendance::create([
            'schedule_lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'status' => 'present',
        ]);
        Attendance::create([
            'schedule_lesson_id' => $otherLesson->id,
            'student_id' => $student->id,
            'status' => 'late',
        ]);
        Attendance::create([
            'schedule_lesson_id' => $lesson->id,
            'student_id' => $otherStudent->id,
            'status' => 'absent',
        ]);

        $this->getJson("/api/reports/attendance-by-group?group_id={$context['group']->id}&date_from=2026-09-01&date_to=2026-09-30")
            ->assertOk()
            ->assertJsonPath('data.summary.total_lessons', 2)
            ->assertJsonPath('data.summary.students_count', 2)
            ->assertJsonPath('data.summary.present', 1)
            ->assertJsonPath('data.summary.absent', 1)
            ->assertJsonPath('data.summary.late', 1)
            ->assertJsonPath('data.summary.unmarked', 1)
            ->assertJsonPath('data.students.0.present', 1)
            ->assertJsonPath('data.students.0.late', 1)
            ->assertJsonPath('data.students.1.absent', 1)
            ->assertJsonPath('data.students.1.unmarked', 1);
    }

    public function test_it_exports_attendance_report_to_csv(): void
    {
        $context = $this->createContext();
        [$student] = $this->createStudents($context['group']);
        [$lesson] = $this->createLessons($context);

        Attendance::create([
            'schedule_lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'status' => 'present',
        ]);

        $response = $this->get("/api/reports/attendance-by-group/export?group_id={$context['group']->id}");

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('student;group;total_lessons', $content);
        $this->assertStringContainsString('Иванов Дмитрий Сергеевич', $content);
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
