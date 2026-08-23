<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ScheduleEntry;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Расписание на стену: дни по столбцам, пары по строкам.
 *
 * Списком расписание не вывешивают. Пока такой формы не было, учебная часть
 * сводила ту же таблицу в Excel руками, имея её готовой в портале.
 */
class SchedulePrintWeekTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_week_puts_days_in_columns_and_lessons_in_rows(): void
    {
        $f = $this->fixture();

        $data = $this->withApiAuth($f['user'])
            ->getJson('/api/schedule/report/week?group_id='.$f['group']->id.'&date_from=2026-09-01&date_to=2026-09-05')
            ->assertOk()
            ->json('data');

        $this->assertSame('group', $data['for']);
        $this->assertSame('ИСП-101', $data['title']);
        $this->assertSame(['вторник, 01.09', 'среда, 02.09'], array_column($data['days'], 'column'));
        $this->assertSame(['1 пара, 08:30–10:05', '2 пара, 10:15–11:50'], array_column($data['rows'], 'title'));

        $first = $data['rows'][0]['cells']['2026-09-01'];
        $this->assertSame(['Сольфеджио', 'Смирнова Е.П.', 'ауд. 201'], $first['lines'], 'В клетке дисциплина, преподаватель и аудитория.');
        $this->assertArrayNotHasKey('2026-09-02', $data['rows'][1]['cells'], 'Во второй день второй пары нет.');
    }

    public function test_teacher_week_shows_the_group_instead_of_the_teacher(): void
    {
        $f = $this->fixture();

        $data = $this->withApiAuth($f['user'])
            ->getJson('/api/schedule/report/week?teacher_id='.$f['teacher']->id.'&date_from=2026-09-01&date_to=2026-09-05')
            ->assertOk()
            ->json('data');

        $this->assertSame('teacher', $data['for']);
        $this->assertSame('Смирнова Е.П.', $data['title']);
        $this->assertSame(['Сольфеджио', 'ИСП-101', 'ауд. 201'], $data['rows'][0]['cells']['2026-09-01']['lines']);
    }

    public function test_week_exports_with_russian_headers(): void
    {
        $f = $this->fixture();

        $csv = $this->withApiAuth($f['user'])
            ->get('/api/schedule/export/week.csv?group_id='.$f['group']->id.'&date_from=2026-09-01&date_to=2026-09-05')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Пара', $csv);
        $this->assertStringContainsString('вторник, 01.09', $csv);
        $this->assertStringContainsString('Сольфеджио', $csv);
    }

    public function test_one_of_group_or_teacher_is_required(): void
    {
        $f = $this->fixture();

        $this->withApiAuth($f['user'])
            ->getJson('/api/schedule/report/week?date_from=2026-09-01&date_to=2026-09-05')
            ->assertStatus(422);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $subject = Subject::create(['name' => 'Сольфеджио']);
        $teacher = Teacher::create(['last_name' => 'Смирнова', 'first_name' => 'Елена', 'middle_name' => 'Петровна', 'is_active' => true]);
        $room = Classroom::create(['number' => '201', 'building' => 'Главный корпус']);

        $this->entry($group, $subject, $teacher, $room, '2026-09-01', 1, '08:30', '10:05');
        $this->entry($group, $subject, $teacher, $room, '2026-09-01', 2, '10:15', '11:50');
        $this->entry($group, $subject, $teacher, $room, '2026-09-02', 1, '08:30', '10:05');

        $role = Role::query()->firstOrCreate(['code' => 'study'], ['name' => 'Study']);
        $role->permissions()->sync([
            Permission::query()->firstOrCreate(
                ['code' => 'schedule.view'],
                ['name' => 'schedule.view', 'module' => 'Schedule', 'system' => true, 'active' => true],
            )->id,
        ]);

        $user = User::factory()->create(['is_active' => true, 'role_id' => $role->id]);

        return compact('group', 'subject', 'teacher', 'room', 'user');
    }

    private function entry(Group $group, Subject $subject, Teacher $teacher, Classroom $room, string $date, int $number, string $from, string $to): void
    {
        ScheduleEntry::create([
            'academic_year' => '2026-2027',
            'semester' => 1,
            'date' => $date,
            'day_of_week' => (int) date('N', strtotime($date)),
            'lesson_number' => $number,
            'starts_at' => $from,
            'ends_at' => $to,
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'classroom_id' => $room->id,
            'status' => 'scheduled',
        ]);
    }
}
