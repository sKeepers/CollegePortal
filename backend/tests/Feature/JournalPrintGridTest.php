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
use Tests\TestCase;

/**
 * Печатная форма журнала: страница бумажного журнала, а не «длинный» список.
 *
 * Выгрузки отдавали строку на каждого студента каждого занятия — читать такое на
 * бумаге нельзя, а учебная часть подшивает журнал именно страницами.
 */
class JournalPrintGridTest extends TestCase
{
    use RefreshDatabase;

    public function test_grid_puts_students_in_rows_and_lessons_in_columns(): void
    {
        $fixture = $this->fixture();

        $data = $this->withApiAuth($fixture['user'])
            ->getJson('/api/journal/report/grid?group_id='.$fixture['group']->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data['lessons'], 'В форме должно быть два занятия.');
        $this->assertSame(['01.09', '01.09 (2)'], array_column($data['lessons'], 'column'), 'Две пары в один день должны различаться в шапке.');

        $first = collect($data['students'])->firstWhere('student_id', $fixture['absent']->id);
        $second = collect($data['students'])->firstWhere('student_id', $fixture['present']->id);

        $cells = array_values($first['cells']);
        $this->assertSame('н', $cells[0], 'Отсутствие обозначается «н».');
        $this->assertSame('оп 4', $cells[1], 'В клетке рядом стоят отметка и оценка.');
        $this->assertSame(1, $first['absences']);
        $this->assertSame(1, $first['lates']);
        $this->assertEquals(4, $first['average'], 'Средний балл считается по числовым оценкам.');

        // Пустая клетка значит «был»: так устроен бумажный журнал.
        $this->assertSame(['', '5'], array_values($second['cells']));
        $this->assertSame(0, $second['absences']);
    }

    public function test_grid_exports_with_russian_headers(): void
    {
        $fixture = $this->fixture();

        $response = $this->withApiAuth($fixture['user'])
            ->get('/api/journal/export/grid.csv?group_id='.$fixture['group']->id)
            ->assertOk();

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", str_replace(["\r\n", "\n"], "\n", $csv))));

        $this->assertStringContainsString('Студент', $lines[0]);
        $this->assertStringContainsString('01.09', $lines[0]);
        $this->assertStringContainsString('Средний балл', $lines[0]);
        $this->assertStringContainsString('н', $csv);
    }

    public function test_stranger_is_refused_with_a_reason(): void
    {
        $fixture = $this->fixture();
        $stranger = User::factory()->create([
            'is_active' => true,
            'role_id' => $this->roleWithPermissions('stranger', ['journal.view'])->id,
        ]);

        $this->withApiAuth($stranger)
            ->getJson('/api/journal/report/grid?group_id='.$fixture['group']->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Журнал этой группы вам не показывают: вы её не курируете и в ней не преподаёте.');
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $subject = Subject::create(['name' => 'Сольфеджио']);
        $teacher = Teacher::create(['last_name' => 'Смирнова', 'first_name' => 'Елена', 'is_active' => true]);

        $absent = Student::create(['group_id' => $group->id, 'last_name' => 'Абрамов', 'first_name' => 'Пётр', 'status' => 'active']);
        $present = Student::create(['group_id' => $group->id, 'last_name' => 'Белова', 'first_name' => 'Анна', 'status' => 'active']);

        $morning = $this->lesson($group, $subject, $teacher, '2026-09-01', '08:30');
        $noon = $this->lesson($group, $subject, $teacher, '2026-09-01', '10:15');

        $this->mark($morning, $absent, 'absent');
        $this->mark($morning, $present, 'present');
        $this->mark($noon, $absent, 'late');
        $this->mark($noon, $present, 'present');

        JournalGrade::create(['journal_lesson_id' => $noon->id, 'student_id' => $absent->id, 'value' => '4']);
        JournalGrade::create(['journal_lesson_id' => $noon->id, 'student_id' => $present->id, 'value' => '5']);

        $user = User::factory()->create([
            'is_active' => true,
            'role_id' => $this->roleWithPermissions('records', ['journal.view', 'journal.view_all', 'journal.export'])->id,
        ]);

        return compact('group', 'subject', 'teacher', 'absent', 'present', 'user');
    }

    private function lesson(Group $group, Subject $subject, Teacher $teacher, string $date, string $from): JournalLesson
    {
        return JournalLesson::create([
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'lesson_date' => $date,
            'starts_at' => $from,
            'ends_at' => substr($from, 0, 2) === '08' ? '10:05' : '11:50',
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

    private function roleWithPermissions(string $code, array $permissions): Role
    {
        $role = Role::query()->firstOrCreate(['code' => $code], ['name' => ucfirst($code)]);
        $ids = collect($permissions)->map(fn (string $permission) => Permission::query()->firstOrCreate(
            ['code' => $permission],
            ['name' => $permission, 'module' => 'Journal', 'description' => null, 'system' => true, 'active' => true],
        )->id);
        $role->permissions()->sync($ids);

        return $role;
    }
}
