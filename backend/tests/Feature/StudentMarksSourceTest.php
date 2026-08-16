<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\JournalAttendance;
use App\Models\JournalGrade;
use App\Models\JournalLesson;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Оценки и отметки студента приходят из журнала, и только оттуда.
 *
 * До 16.08.2026 в портале было две несвязанные пары таблиц: журнал писал в
 * `journal_grades` и `journal_attendance`, а карточка студента, его кабинет и
 * отчёты читали старые `grades` и `attendance`. Оценка, поставленная
 * преподавателем, не появлялась у студента нигде. На стенде это было не видно:
 * демонстрационный набор наполнял обе пары одинаково, и цифры всегда сходились.
 *
 * Владелец решил 16.08.2026 свести их сразу — живых данных в старой паре не
 * было. Этот тест держит результат: единственный источник — журнал, второго
 * пути записи нет.
 */
class StudentMarksSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));
        $this->withApiAuth();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_grades_list_shows_what_the_teacher_put_in_the_journal(): void
    {
        $world = $this->world();

        $grade = JournalGrade::create([
            'journal_lesson_id' => $world['lesson']->id,
            'student_id' => $world['student']->id,
            'value' => '5',
            'marked_by' => $world['teacherUser']->id,
            'marked_at' => now(),
        ]);

        $this->getJson("/api/grades?student_id={$world['student']->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $grade->id)
            ->assertJsonPath('data.0.grade', '5')
            ->assertJsonPath('data.0.lesson.subject.name', 'Сольфеджио')
            ->assertJsonPath('data.0.lesson.lesson_date', '2026-08-14');
    }

    public function test_attendance_list_skips_the_roster_rows_the_journal_creates_itself(): void
    {
        $world = $this->world();

        // Заготовка, которую журнал создаёт при открытии занятия: никто ещё
        // никого не отмечал.
        JournalAttendance::create([
            'journal_lesson_id' => $world['lesson']->id,
            'student_id' => $world['student']->id,
            'status' => 'present',
            'source' => 'roster',
            'marked_by' => $world['teacherUser']->id,
            'marked_at' => now(),
        ]);

        $this->getJson("/api/attendance?student_id={$world['student']->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $marked = JournalAttendance::create([
            'journal_lesson_id' => $this->lesson($world, '2026-08-15')->id,
            'student_id' => $world['student']->id,
            'status' => 'late',
            'minutes_late' => 7,
            'source' => 'manual',
            'marked_by' => $world['teacherUser']->id,
            'marked_at' => now(),
        ]);

        $this->getJson("/api/attendance?student_id={$world['student']->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $marked->id)
            ->assertJsonPath('data.0.status', 'late')
            ->assertJsonPath('data.0.minutes_late', 7);
    }

    public function test_there_is_no_second_way_to_write_a_mark(): void
    {
        $world = $this->world();

        // Старый путь записи снят целиком: оценку и отметку ставят в журнале,
        // где у них есть занятие, автор и подпись.
        $this->postJson('/api/grades', [
            'schedule_lesson_id' => 1,
            'student_id' => $world['student']->id,
            'grade' => '5',
        ])->assertStatus(405);

        $this->postJson('/api/attendance', [
            'schedule_lesson_id' => 1,
            'student_id' => $world['student']->id,
            'status' => 'present',
        ])->assertStatus(405);

        $this->assertSame(0, JournalGrade::query()->count());
        $this->assertSame(0, JournalAttendance::query()->count());
    }

    public function test_group_reports_count_journal_marks(): void
    {
        $world = $this->world();

        JournalGrade::create([
            'journal_lesson_id' => $world['lesson']->id,
            'student_id' => $world['student']->id,
            'value' => '4',
            'marked_by' => $world['teacherUser']->id,
            'marked_at' => now(),
        ]);
        JournalAttendance::create([
            'journal_lesson_id' => $world['lesson']->id,
            'student_id' => $world['student']->id,
            'status' => 'sick',
            'source' => 'manual',
            'marked_by' => $world['teacherUser']->id,
            'marked_at' => now(),
        ]);

        $this->getJson("/api/reports/grades-by-group?group_id={$world['group']->id}&subject_id={$world['subject']->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.grades_count', 1)
            ->assertJsonPath('data.summary.average_grade', 4);

        // «Болел» — статус журнала, которого старая таблица не знала: без него
        // отметка потерялась бы по дороге в отчёт.
        $this->getJson("/api/reports/attendance-by-group?group_id={$world['group']->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.sick', 1)
            ->assertJsonPath('data.summary.total_lessons', 1)
            ->assertJsonPath('data.students.0.unmarked', 0);
    }

    public function test_student_cabinet_shows_the_journal_grade(): void
    {
        $world = $this->world();

        JournalGrade::create([
            'journal_lesson_id' => $world['lesson']->id,
            'student_id' => $world['student']->id,
            'value' => '3',
            'marked_by' => $world['teacherUser']->id,
            'marked_at' => now(),
        ]);

        $studentUser = User::factory()->create([
            'is_active' => true,
            'role_id' => $this->roleWithPermissions('student-cabinet', ['mobile.student.view'])->id,
        ]);
        $world['student']->update(['user_id' => $studentUser->id]);

        $this->withApiAuth($studentUser)
            ->getJson('/api/mobile/student')
            ->assertOk()
            ->assertJsonPath('data.grades.0.grade', '3')
            ->assertJsonPath('data.grades.0.lesson.subject.name', 'Сольфеджио');
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $teacherUser = User::factory()->create(['is_active' => true]);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'last_name' => 'Петров', 'first_name' => 'Игорь', 'is_active' => true]);
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $subject = Subject::create(['name' => 'Сольфеджио']);
        $student = Student::create(['group_id' => $group->id, 'last_name' => 'Абрамов', 'first_name' => 'Пётр', 'status' => 'active']);

        $world = compact('teacherUser', 'teacher', 'group', 'subject', 'student');
        $world['lesson'] = $this->lesson($world, '2026-08-14');

        return $world;
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

    /** @param  array<string, mixed>  $world */
    private function lesson(array $world, string $date): JournalLesson
    {
        return JournalLesson::create([
            'group_id' => $world['group']->id,
            'subject_id' => $world['subject']->id,
            'teacher_id' => $world['teacher']->id,
            'lesson_date' => $date,
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'topic' => 'Тема занятия',
            'status' => JournalLesson::STATUS_IN_PROGRESS,
        ]);
    }
}
