<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\JournalGrade;
use App\Models\JournalLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Отчёт об успеваемости группы считает оценки журнала — те, что ставит
 * преподаватель. Старая таблица `grades` перестала быть источником 16.08.2026.
 */
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

        $this->grade($lesson, $student, '5');
        $this->grade($otherLesson, $student, '4');
        // Зачёт в среднее не входит, но в число оценок — да.
        $this->grade($lesson, $otherStudent, 'зачет');

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

    public function test_an_empty_grade_is_not_a_grade(): void
    {
        $context = $this->createContext();
        [$student] = $this->createStudents($context['group']);
        [$lesson] = $this->createLessons($context);

        // Пустое значение журнал хранит как «оценки нет»: стёртая оценка не
        // должна попадать в счёт.
        $this->grade($lesson, $student, '');

        $this->getJson("/api/reports/grades-by-group?group_id={$context['group']->id}&subject_id={$context['subject']->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.grades_count', 0)
            ->assertJsonPath('data.summary.average_grade', null);
    }

    public function test_it_exports_grade_report_to_csv(): void
    {
        $context = $this->createContext();
        [$student] = $this->createStudents($context['group']);
        [$lesson] = $this->createLessons($context);

        $this->grade($lesson, $student, '5');

        $response = $this->get("/api/reports/grades-by-group/export?group_id={$context['group']->id}&subject_id={$context['subject']->id}");

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('student;group;subject;grades', $content);
        $this->assertStringContainsString('Иванов Дмитрий Сергеевич', $content);
        $this->assertStringContainsString('Сольфеджио', $content);
    }

    private function grade(JournalLesson $lesson, Student $student, string $value): JournalGrade
    {
        return JournalGrade::create([
            'journal_lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'value' => $value,
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
