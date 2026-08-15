<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Отчёты по группе строятся по своим группам, а не по любой.
 *
 * Право `journal.view` есть у каждого преподавателя, а `group_id` до 16.08.2026
 * брался из запроса как есть: оценки и посещаемость любой группы колледжа
 * выгружал кто угодно из преподавательской. Владелец распорядился разграничить
 * 16.08.2026.
 *
 * «Своя группа» — это две связи, и обе настоящие: куратор отвечает за группу,
 * преподаватель ведёт в ней занятия. Тест держит обе: отрезав вторую, мы
 * сломали бы преподавателю его ежедневный отчёт.
 */
class GroupReportScopeTest extends TestCase
{
    use RefreshDatabase;

    /** Все четыре маршрута: два отчёта и две выгрузки. */
    private const ROUTES = [
        '/api/reports/attendance-by-group?group_id=%d',
        '/api/reports/attendance-by-group/export?group_id=%d',
        '/api/reports/grades-by-group?group_id=%d&subject_id=%d',
        '/api/reports/grades-by-group/export?group_id=%d&subject_id=%d',
    ];

    public function test_curator_builds_reports_for_their_own_group(): void
    {
        $world = $this->world();

        foreach ($this->urls($world['ownGroup']->id, $world['subject']->id) as $url) {
            $this->withApiAuth($world['curatorUser'])->get($url)->assertSuccessful();
        }
    }

    public function test_foreign_group_is_refused_on_every_report_route(): void
    {
        $world = $this->world();

        foreach ($this->urls($world['foreignGroup']->id, $world['subject']->id) as $url) {
            $this->withApiAuth($world['curatorUser'])
                ->getJson($url)
                ->assertForbidden()
                ->assertJsonPath('message', 'Отчёт строится по своим группам: где вы куратор или ведёте занятия.');
        }
    }

    public function test_teaching_in_the_group_is_enough_to_build_its_report(): void
    {
        $world = $this->world();

        // Преподаватель ведёт занятие в чужой для него группе — отчёт по ней
        // его ежедневная работа, и запрет здесь сломал бы работающее.
        ScheduleLesson::create([
            'group_id' => $world['foreignGroup']->id,
            'teacher_id' => $world['plainTeacher']->id,
            'subject_id' => $world['subject']->id,
            'classroom_id' => $world['classroom']->id,
            'lesson_date' => '2026-08-14',
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'lesson_type' => 'lesson',
        ]);

        foreach ($this->urls($world['foreignGroup']->id, $world['subject']->id) as $url) {
            $this->withApiAuth($world['plainTeacherUser'])->get($url)->assertSuccessful();
        }

        // А в группу, где он не ведёт и не куратор, его по-прежнему не пускают.
        $this->withApiAuth($world['plainTeacherUser'])
            ->getJson(sprintf(self::ROUTES[0], $world['ownGroup']->id))
            ->assertForbidden();
    }

    public function test_whoever_sees_the_whole_journal_builds_any_report(): void
    {
        $world = $this->world();
        $deputy = User::factory()->create([
            'is_active' => true,
            'role_id' => $this->roleWithPermissions('deputy-reports', ['journal.view', 'journal.view_all', 'journal.export'])->id,
        ]);

        foreach ($this->urls($world['foreignGroup']->id, $world['subject']->id) as $url) {
            $this->withApiAuth($deputy)->get($url)->assertSuccessful();
        }
    }

    /** @return list<string> */
    private function urls(int $groupId, int $subjectId): array
    {
        return array_map(
            fn (string $template): string => sprintf($template, $groupId, $subjectId),
            self::ROUTES,
        );
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $role = $this->roleWithPermissions('curator-reports', ['journal.view', 'journal.export']);

        $curatorUser = User::factory()->create(['is_active' => true, 'role_id' => $role->id]);
        $curator = Teacher::create(['user_id' => $curatorUser->id, 'last_name' => 'Смирнова', 'first_name' => 'Елена', 'is_active' => true]);

        $plainTeacherUser = User::factory()->create(['is_active' => true, 'role_id' => $role->id]);
        $plainTeacher = Teacher::create(['user_id' => $plainTeacherUser->id, 'last_name' => 'Петров', 'first_name' => 'Игорь', 'is_active' => true]);

        $ownGroup = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026, 'curator_id' => $curator->id]);
        $foreignGroup = Group::create(['name' => 'ВОК-201', 'specialty' => 'Вокальное искусство', 'course' => 2, 'year_start' => 2025]);

        Student::create(['group_id' => $ownGroup->id, 'last_name' => 'Абрамов', 'first_name' => 'Пётр', 'status' => 'active']);
        Student::create(['group_id' => $foreignGroup->id, 'last_name' => 'Чужаков', 'first_name' => 'Семён', 'status' => 'active']);

        return [
            'curatorUser' => $curatorUser,
            'curator' => $curator,
            'plainTeacherUser' => $plainTeacherUser,
            'plainTeacher' => $plainTeacher,
            'ownGroup' => $ownGroup,
            'foreignGroup' => $foreignGroup,
            'subject' => Subject::create(['name' => 'Сольфеджио']),
            'classroom' => Classroom::create(['number' => '201', 'building' => 'Главный корпус']),
        ];
    }

    /** @param  list<string>  $permissions */
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
