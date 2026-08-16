<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\JournalAttendance;
use App\Models\JournalLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Отчёт о посещаемости группы считает отметки журнала — те, что ставит
 * преподаватель. Старая таблица `attendance` перестала быть источником
 * 16.08.2026.
 */
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

        $this->mark($lesson, $student, 'present');
        $this->mark($otherLesson, $student, 'late');
        $this->mark($lesson, $otherStudent, 'absent');

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

    public function test_roster_rows_are_not_counted_as_marks(): void
    {
        $context = $this->createContext();
        [$student] = $this->createStudents($context['group']);
        [$lesson] = $this->createLessons($context);

        // Строку `roster` журнал создаёт сам при открытии занятия. Считать её
        // присутствием значит отчитаться за занятие, которое ещё не вели.
        $this->mark($lesson, $student, 'present', 'roster');

        $this->getJson("/api/reports/attendance-by-group?group_id={$context['group']->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.present', 0)
            ->assertJsonPath('data.students.0.unmarked', 2);
    }

    public function test_it_exports_attendance_report_to_csv(): void
    {
        $context = $this->createContext();
        [$student] = $this->createStudents($context['group']);
        [$lesson] = $this->createLessons($context);

        $this->mark($lesson, $student, 'present');

        $response = $this->get("/api/reports/attendance-by-group/export?group_id={$context['group']->id}");

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('student;group;total_lessons', $content);
        $this->assertStringContainsString('sick;remote;unmarked', $content);
        $this->assertStringContainsString('Иванов Дмитрий Сергеевич', $content);
    }

    private function mark(JournalLesson $lesson, Student $student, string $status, string $source = 'manual'): JournalAttendance
    {
        return JournalAttendance::create([
            'journal_lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'status' => $status,
            'source' => $source,
            'marked_at' => now(),
        ]);
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
            $this->lesson($context, '2026-09-02'),
            $this->lesson($context, '2026-09-03'),
        ];
    }

    private function lesson(array $context, string $date): JournalLesson
    {
        return JournalLesson::create([
            'group_id' => $context['group']->id,
            'teacher_id' => $context['teacher']->id,
            'subject_id' => $context['subject']->id,
            'lesson_date' => $date,
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'status' => JournalLesson::STATUS_IN_PROGRESS,
        ]);
    }
}
