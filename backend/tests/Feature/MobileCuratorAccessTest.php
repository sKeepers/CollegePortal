<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Куратор и чужая группа.
 *
 * Это единственное место `MOB-002`, где ошибка означает утечку персональных
 * данных: куратор видит телефоны и почту студентов и события проходной. Право
 * `mobile.curator.view` открывает раздел всем кураторам сразу, поэтому
 * разграничение держится не на нём, а на `groups.curator_id`, и проверяется в
 * самом эндпоинте.
 */
class MobileCuratorAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Все маршруты кабинета, принимающие идентификатор группы. */
    private const GROUP_ROUTES = [
        '/api/mobile/curator/groups/%d',
        '/api/mobile/curator/groups/%d/attendance',
        '/api/mobile/curator/groups/%d/access',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-13 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_curator_sees_only_the_groups_assigned_to_them(): void
    {
        $world = $this->world();

        $payload = $this->withApiAuth($world['curatorUser'])
            ->getJson('/api/mobile/curator')
            ->assertOk()
            ->assertJsonPath('data.curator.id', $world['curator']->id)
            ->json('data');

        $this->assertSame([$world['ownGroup']->id], array_column($payload['groups'], 'id'));
        $this->assertNotContains(
            $world['foreignGroup']->id,
            array_column($payload['groups'], 'id'),
            'Чужая группа попала в список куратора.',
        );
    }

    public function test_every_group_route_refuses_a_substituted_foreign_group(): void
    {
        $world = $this->world();

        foreach (self::GROUP_ROUTES as $template) {
            $own = sprintf($template, $world['ownGroup']->id);
            $foreign = sprintf($template, $world['foreignGroup']->id);

            $this->withApiAuth($world['curatorUser'])->getJson($own)->assertOk();
            $this->withApiAuth($world['curatorUser'])->getJson($foreign)
                ->assertForbidden()
                ->assertJsonPath('message', 'Эта группа не закреплена за вами.');
        }
    }

    public function test_own_group_payload_carries_no_student_from_another_group(): void
    {
        $world = $this->world();

        $payload = $this->withApiAuth($world['curatorUser'])
            ->getJson("/api/mobile/curator/groups/{$world['ownGroup']->id}")
            ->assertOk()
            ->json('data');

        $names = array_column($payload['students'], 'last_name');
        $this->assertSame(['Абрамов', 'Белова'], $names);
        $this->assertNotContains('Чужаков', $names, 'Студент чужой группы попал в список.');

        // Контакты — то, ради чего запрет и нужен: они здесь есть, и значит
        // чужая группа не должна отдаваться ни при каких условиях.
        $this->assertSame('+79990000001', $payload['students'][0]['phone']);

        $attendance = $this->withApiAuth($world['curatorUser'])
            ->getJson("/api/mobile/curator/groups/{$world['ownGroup']->id}/attendance")
            ->assertOk()
            ->json('data');

        $this->assertNotContains('Чужаков Семён', array_column($attendance['rows'], 'full_name'));
        $this->assertSame([$world['ownStudents'][0]->id, $world['ownStudents'][1]->id], array_column($attendance['rows'], 'entity_id'));

        $access = $this->withApiAuth($world['curatorUser'])
            ->getJson("/api/mobile/curator/groups/{$world['ownGroup']->id}/access")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $access['events'], 'В журнале проходной оказалось чужое событие.');
        $this->assertSame($world['ownStudents'][0]->id, $access['events'][0]['entity_id']);
    }

    public function test_teaching_in_a_group_does_not_make_you_its_curator(): void
    {
        $world = $this->world();

        // Куратор ведёт занятие в чужой группе: журнал этого занятия ему открыт,
        // а контакты и проходная чужой группы — нет. Это самый вероятный случай
        // в жизни, и именно на нём разграничение обычно и протекает.
        ScheduleLesson::create([
            'group_id' => $world['foreignGroup']->id,
            'teacher_id' => $world['curator']->id,
            'subject_id' => $world['subject']->id,
            'classroom_id' => $world['classroom']->id,
            'lesson_date' => '2026-07-13',
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'lesson_type' => 'lesson',
        ]);

        foreach (self::GROUP_ROUTES as $template) {
            $this->withApiAuth($world['curatorUser'])
                ->getJson(sprintf($template, $world['foreignGroup']->id))
                ->assertForbidden();
        }

        $this->withApiAuth($world['curatorUser'])
            ->getJson('/api/mobile/curator')
            ->assertOk()
            ->assertJsonCount(1, 'data.groups');
    }

    public function test_curator_without_a_group_gets_an_empty_cabinet_not_forbidden(): void
    {
        $world = $this->world();

        $lonelyUser = User::factory()->create(['is_active' => true, 'role_id' => $world['curatorRole']->id]);
        Teacher::create(['user_id' => $lonelyUser->id, 'last_name' => 'Одинокий', 'first_name' => 'Куратор', 'is_active' => true]);

        $this->withApiAuth($lonelyUser)
            ->getJson('/api/mobile/curator')
            ->assertOk()
            ->assertJsonCount(0, 'data.groups')
            ->assertJsonPath('data.message', 'За вами не закреплено ни одной группы.');

        // И всё равно не открывает чужую: пустой список это не пропуск.
        $this->withApiAuth($lonelyUser)
            ->getJson("/api/mobile/curator/groups/{$world['ownGroup']->id}")
            ->assertForbidden();
    }

    public function test_user_without_a_teacher_card_gets_an_empty_cabinet_not_forbidden(): void
    {
        $world = $this->world();
        $stranger = User::factory()->create(['is_active' => true, 'role_id' => $world['curatorRole']->id]);

        $this->withApiAuth($stranger)
            ->getJson('/api/mobile/curator')
            ->assertOk()
            ->assertJsonPath('data.curator', null)
            ->assertJsonPath('data.message', 'Текущий пользователь не связан с карточкой преподавателя.')
            ->assertJsonCount(0, 'data.groups');

        $this->withApiAuth($stranger)
            ->getJson("/api/mobile/curator/groups/{$world['ownGroup']->id}")
            ->assertForbidden();
    }

    public function test_access_follows_the_assignment_and_is_not_frozen_at_login(): void
    {
        $world = $this->world();

        $this->withApiAuth($world['curatorUser'])
            ->getJson("/api/mobile/curator/groups/{$world['foreignGroup']->id}")
            ->assertForbidden();

        $world['foreignGroup']->update(['curator_id' => $world['curator']->id]);

        $this->withApiAuth($world['curatorUser'])
            ->getJson("/api/mobile/curator/groups/{$world['foreignGroup']->id}")
            ->assertOk();
        $this->withApiAuth($world['curatorUser'])
            ->getJson('/api/mobile/curator')
            ->assertOk()
            ->assertJsonCount(2, 'data.groups');

        $world['ownGroup']->update(['curator_id' => null]);

        $this->withApiAuth($world['curatorUser'])
            ->getJson("/api/mobile/curator/groups/{$world['ownGroup']->id}")
            ->assertForbidden();
    }

    public function test_section_permission_is_required(): void
    {
        $world = $this->world();
        $withoutSection = User::factory()->create([
            'is_active' => true,
            'role_id' => $this->roleWithPermissions('teacher-no-mobile', ['journal.view'])->id,
        ]);
        Teacher::create(['user_id' => $withoutSection->id, 'last_name' => 'Безправный', 'first_name' => 'Пётр', 'is_active' => true]);

        $this->withApiAuth($withoutSection)->getJson('/api/mobile/curator')->assertForbidden();
        $this->withApiAuth($withoutSection)
            ->getJson("/api/mobile/curator/groups/{$world['ownGroup']->id}")
            ->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $curatorRole = $this->roleWithPermissions('curator', [
            'mobile.curator.view', 'mobile.teacher.view', 'students.view', 'groups.view', 'attendance.reports',
        ]);

        $curatorUser = User::factory()->create(['is_active' => true, 'role_id' => $curatorRole->id]);
        $curator = Teacher::create(['user_id' => $curatorUser->id, 'last_name' => 'Смирнова', 'first_name' => 'Елена', 'is_active' => true]);

        $otherUser = User::factory()->create(['is_active' => true, 'role_id' => $curatorRole->id]);
        $otherCurator = Teacher::create(['user_id' => $otherUser->id, 'last_name' => 'Петров', 'first_name' => 'Игорь', 'is_active' => true]);

        $ownGroup = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026, 'curator_id' => $curator->id]);
        $foreignGroup = Group::create(['name' => 'ВОК-201', 'specialty' => 'Вокальное искусство', 'course' => 2, 'year_start' => 2025, 'curator_id' => $otherCurator->id]);

        $ownStudents = [
            Student::create(['group_id' => $ownGroup->id, 'last_name' => 'Абрамов', 'first_name' => 'Пётр', 'status' => 'active', 'phone' => '+79990000001', 'email' => 'abramov@example.test']),
            Student::create(['group_id' => $ownGroup->id, 'last_name' => 'Белова', 'first_name' => 'Анна', 'status' => 'active', 'phone' => '+79990000002']),
        ];
        $foreignStudent = Student::create(['group_id' => $foreignGroup->id, 'last_name' => 'Чужаков', 'first_name' => 'Семён', 'status' => 'active', 'phone' => '+79990000003']);

        AccessEvent::create([
            'entity_type' => 'student',
            'entity_id' => $ownStudents[0]->id,
            'direction' => AccessEvent::DIRECTION_IN,
            'result' => AccessEvent::RESULT_ALLOWED,
            'event_time' => Carbon::parse('2026-07-13 08:40:00'),
        ]);
        AccessEvent::create([
            'entity_type' => 'student',
            'entity_id' => $foreignStudent->id,
            'direction' => AccessEvent::DIRECTION_IN,
            'result' => AccessEvent::RESULT_ALLOWED,
            'event_time' => Carbon::parse('2026-07-13 08:45:00'),
        ]);

        return [
            'curatorRole' => $curatorRole,
            'curatorUser' => $curatorUser,
            'curator' => $curator,
            'ownGroup' => $ownGroup,
            'foreignGroup' => $foreignGroup,
            'ownStudents' => $ownStudents,
            'foreignStudent' => $foreignStudent,
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
            ['name' => $permission, 'module' => 'Mobile', 'description' => null, 'system' => true, 'active' => true],
        )->id);
        $role->permissions()->sync($ids);

        return $role;
    }
}
