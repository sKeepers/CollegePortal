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
 * Успеваемость группы в кабинете куратора.
 *
 * Её не было вовсе: были состав, проходная и «посещаемость», а посещаемость там —
 * проходная, то есть был ли проход через турникет. С куратора спрашивают учёбу, и
 * ответа на это в телефоне не было ни в каком виде.
 */
class MobileCuratorPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_performance_counts_journal_marks_and_grades(): void
    {
        $f = $this->fixture();

        $data = $this->withApiAuth($f['user'])
            ->getJson('/api/mobile/curator/groups/'.$f['group']->id.'/performance?date_from=2026-09-01&date=2026-09-10')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['lessons'], 'Занятий за период два.');
        $this->assertStringContainsString('Журнал', $data['source']);

        $rows = collect($data['rows']);
        $weak = $rows->firstWhere('student_id', $f['weak']->id);
        $good = $rows->firstWhere('student_id', $f['good']->id);

        $this->assertSame(1, $weak['absences']);
        $this->assertSame(1, $weak['lates']);
        $this->assertEquals(3, $weak['average']);

        $this->assertSame(0, $good['absences']);
        $this->assertEquals(4.5, $good['average']);

        $this->assertSame(1, $data['summary']['absences']);
        $this->assertSame(1, $data['summary']['lates']);
    }

    public function test_roster_rows_are_not_counted_as_marks(): void
    {
        $f = $this->fixture();

        // Занятие, где преподаватель не отметил никого: журнал при открытии сам
        // завёл строки со `source = roster`. Отметками они не являются.
        $opened = $this->lesson($f['group'], Subject::first(), Teacher::where('last_name', 'Ведущев')->first(), '2026-09-09');
        JournalAttendance::create([
            'journal_lesson_id' => $opened->id,
            'student_id' => $f['good']->id,
            'status' => 'absent',
            'source' => 'roster',
        ]);

        $data = $this->withApiAuth($f['user'])
            ->getJson('/api/mobile/curator/groups/'.$f['group']->id.'/performance?date_from=2026-09-01&date=2026-09-10')
            ->assertOk()
            ->json('data');

        $good = collect($data['rows'])->firstWhere('student_id', $f['good']->id);
        $this->assertSame(0, $good['absences'], 'Заготовка журнала посчиталась пропуском.');
    }

    public function test_someone_elses_group_is_refused(): void
    {
        $f = $this->fixture();
        $other = Group::create(['name' => 'ИСП-202', 'specialty' => 'Проба', 'course' => 2, 'year_start' => 2025]);

        $this->withApiAuth($f['user'])
            ->getJson('/api/mobile/curator/groups/'.$other->id.'/performance')
            ->assertForbidden()
            ->assertJsonPath('message', 'Эта группа не закреплена за вами.');
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $user = User::factory()->create([
            'is_active' => true,
            'role_id' => $this->roleWith(['mobile.curator.view'])->id,
        ]);
        $curator = Teacher::create(['user_id' => $user->id, 'last_name' => 'Кураторов', 'first_name' => 'Кузьма', 'is_active' => true]);

        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Проба', 'course' => 1, 'year_start' => 2026, 'curator_id' => $curator->id]);
        $subject = Subject::create(['name' => 'Сольфеджио']);
        $teacher = Teacher::create(['last_name' => 'Ведущев', 'first_name' => 'Вадим', 'is_active' => true]);

        $weak = Student::create(['group_id' => $group->id, 'last_name' => 'Аксёнов', 'first_name' => 'Артём', 'status' => 'active']);
        $good = Student::create(['group_id' => $group->id, 'last_name' => 'Волкова', 'first_name' => 'Вера', 'status' => 'active']);

        $first = $this->lesson($group, $subject, $teacher, '2026-09-07');
        $second = $this->lesson($group, $subject, $teacher, '2026-09-08');

        $this->mark($first, $weak, 'absent');
        $this->mark($first, $good, 'present');
        $this->mark($second, $weak, 'late');
        $this->mark($second, $good, 'present');

        JournalGrade::create(['journal_lesson_id' => $second->id, 'student_id' => $weak->id, 'value' => '3']);
        JournalGrade::create(['journal_lesson_id' => $first->id, 'student_id' => $good->id, 'value' => '5']);
        JournalGrade::create(['journal_lesson_id' => $second->id, 'student_id' => $good->id, 'value' => '4']);

        return compact('user', 'curator', 'group', 'weak', 'good', 'first', 'second');
    }

    private function lesson(Group $group, Subject $subject, Teacher $teacher, string $date): JournalLesson
    {
        return JournalLesson::create([
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'lesson_date' => $date,
            'starts_at' => '08:30',
            'ends_at' => '10:05',
            'status' => JournalLesson::STATUS_IN_PROGRESS,
        ]);
    }

    private function mark(JournalLesson $lesson, Student $student, string $status): void
    {
        JournalAttendance::create([
            'journal_lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'status' => $status,
            'source' => 'manual',
        ]);
    }

    private function roleWith(array $permissions): Role
    {
        $role = Role::query()->firstOrCreate(['code' => 'curator'], ['name' => 'Куратор']);
        $role->permissions()->sync(collect($permissions)->map(fn (string $code) => Permission::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'module' => 'Mobile', 'system' => true, 'active' => true],
        )->id));

        return $role;
    }
}
