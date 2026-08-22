<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\AccessPoint;
use App\Models\Building;
use App\Models\DormAbsence;
use App\Models\DormPlacement;
use App\Models\DormRoom;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\DormNightAbsenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ночные отсутствия: правило «вышел и не вернулся до утра».
 *
 * Первый тест — тот самый человек, на котором владелец проверял правило: вышел
 * покурить в 23:00, вернулся в 23:10. Два других правила из разбора объявили бы
 * его отсутствующим, это — нет.
 */
class DormNightAbsenceTest extends TestCase
{
    use RefreshDatabase;

    private const NIGHT = '2026-09-10';

    public function test_the_smoker_who_came_back_is_not_absent(): void
    {
        $student = $this->resident();

        $this->pass($student, '2026-09-10 23:00:00', AccessEvent::DIRECTION_OUT);
        $this->pass($student, '2026-09-10 23:10:00', AccessEvent::DIRECTION_IN);

        $summary = app(DormNightAbsenceService::class)->recalculate(self::NIGHT);

        $this->assertSame(0, $summary['counted']);
        $this->assertSame(0, DormAbsence::query()->count());
    }

    public function test_the_one_who_did_not_return_is_absent(): void
    {
        $student = $this->resident();

        $this->pass($student, '2026-09-10 22:00:00', AccessEvent::DIRECTION_OUT);

        $summary = app(DormNightAbsenceService::class)->recalculate(self::NIGHT);

        $this->assertSame(1, $summary['counted']);
        $absence = DormAbsence::query()->firstWhere('student_id', $student->id);
        $this->assertNotNull($absence);
        $this->assertSame('2026-09-10 22:00:00', $absence->left_at?->format('Y-m-d H:i:s'));
    }

    public function test_the_one_who_never_left_is_not_absent(): void
    {
        $student = $this->resident();

        // Правило «не было ни одного прохода за сутки» назвало бы его самым
        // отсутствующим. Он просто никуда не выходил.
        $this->pass($student, '2026-09-10 19:00:00', AccessEvent::DIRECTION_IN);

        $this->assertSame(0, app(DormNightAbsenceService::class)->recalculate(self::NIGHT)['counted']);
    }

    public function test_an_approved_leave_is_subtracted_before_the_count(): void
    {
        $student = $this->resident();
        $this->pass($student, '2026-09-10 18:00:00', AccessEvent::DIRECTION_OUT);

        $this->withApiAuth($this->warden());
        $this->postJson('/api/dorm/leaves', [
            'student_id' => $student->id,
            'starts_on' => '2026-09-10',
            'ends_on' => '2026-09-12',
            'reason' => 'Домой на выходные',
        ])->assertCreated();

        // Без отлучки правило собирало бы каждую пятницу половину этажа.
        $summary = app(DormNightAbsenceService::class)->recalculate(self::NIGHT);

        $this->assertSame(0, $summary['counted']);
        $this->assertSame(1, $summary['skipped_by_leave']);
    }

    public function test_entering_a_teaching_building_does_not_close_the_night(): void
    {
        $student = $this->resident();
        // Свой код, а не настоящий: настоящие корпуса заводит миграция, и
        // `GOL21` в базе уже есть.
        $other = Building::create(['name' => 'Учебный корпус для проверки', 'code' => 'TEST-EDU', 'is_active' => true]);
        $otherPoint = AccessPoint::create(['building_id' => $other->id, 'name' => 'Главный вход', 'code' => 'TEST-EDU-MAIN', 'is_active' => true]);

        $this->pass($student, '2026-09-10 22:00:00', AccessEvent::DIRECTION_OUT);
        AccessEvent::create([
            'access_point_id' => $otherPoint->id,
            'entity_type' => 'student',
            'entity_id' => $student->id,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => '2026-09-10 23:30:00',
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);

        // Расчёт берёт только двери общежития: иначе вошедший в учебный корпус
        // закрыл бы себе ночь.
        $this->assertSame(1, app(DormNightAbsenceService::class)->recalculate(self::NIGHT)['counted']);
    }

    public function test_a_refused_pass_does_not_count_as_a_return(): void
    {
        $student = $this->resident();

        $this->pass($student, '2026-09-10 21:00:00', AccessEvent::DIRECTION_OUT);
        $this->pass($student, '2026-09-10 23:50:00', AccessEvent::DIRECTION_IN, AccessEvent::RESULT_DENIED);

        // Отказ — не проход: человек остался по ту же сторону двери.
        $this->assertSame(1, app(DormNightAbsenceService::class)->recalculate(self::NIGHT)['counted']);
    }

    public function test_a_leave_added_afterwards_clears_the_night(): void
    {
        $student = $this->resident();
        $this->pass($student, '2026-09-10 22:00:00', AccessEvent::DIRECTION_OUT);

        $this->withApiAuth($this->warden());
        $this->postJson('/api/dorm/absences/recalculate', ['night' => self::NIGHT])
            ->assertOk()
            ->assertJsonPath('data.counted', 1);

        $this->postJson('/api/dorm/leaves', [
            'student_id' => $student->id,
            'starts_on' => '2026-09-09',
            'ends_on' => '2026-09-11',
        ])->assertCreated();

        // Ночь считается начисто, поэтому отлучка задним числом убирает
        // отсутствие, а не оставляет его висеть.
        $this->postJson('/api/dorm/absences/recalculate', ['night' => self::NIGHT])
            ->assertOk()
            ->assertJsonPath('data.counted', 0);

        $this->assertSame(0, DormAbsence::query()->count());
    }

    public function test_a_student_who_moved_out_is_not_counted(): void
    {
        $student = $this->resident();
        DormPlacement::query()->where('student_id', $student->id)->update(['moved_out_at' => '2026-09-01']);

        $this->pass($student, '2026-09-10 22:00:00', AccessEvent::DIRECTION_OUT);

        $summary = app(DormNightAbsenceService::class)->recalculate(self::NIGHT);

        $this->assertSame(0, $summary['residents']);
        $this->assertSame(0, $summary['counted']);
    }

    public function test_without_the_dorm_building_the_count_refuses_and_says_why(): void
    {
        $this->resident();
        Setting::query()->where('group', 'dorm')->where('key', 'building_id')->delete();

        $this->withApiAuth($this->warden());

        $this->postJson('/api/dorm/absences/recalculate', ['night' => self::NIGHT])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Не задан корпус общежития. Укажите его в настройках («Общежитие» → «Корпус общежития»), иначе считать не по чему: расчёт берёт проходы только его дверей.');
    }

    public function test_the_deputy_reads_the_nights_but_does_not_recount_them(): void
    {
        $this->withApiAuth($this->userWith(['dorm.absences.view']));

        $this->getJson('/api/dorm/absences')->assertOk();
        $this->postJson('/api/dorm/absences/recalculate', ['night' => self::NIGHT])->assertForbidden();
    }

    private function resident(string $lastName = 'Проживающий'): Student
    {
        $building = Building::query()->firstWhere('code', 'SER277') ?? Building::create([
            'name' => 'Общежитие на Серова, 277',
            'code' => 'SER277',
            'is_active' => true,
        ]);

        AccessPoint::query()->firstWhere('code', 'SER277-MAIN') ?? AccessPoint::create([
            'building_id' => $building->id,
            'name' => 'Вход в общежитие',
            'code' => 'SER277-MAIN',
            'is_active' => true,
        ]);

        Setting::query()->updateOrCreate(
            ['group' => 'dorm', 'key' => 'building_id'],
            ['value' => $building->id, 'type' => 'integer', 'is_public' => false],
        );

        $room = DormRoom::query()->firstWhere('number', '12') ?? DormRoom::create([
            'building_id' => $building->id,
            'number' => '12',
            'floor' => 1,
            'capacity' => 4,
            'is_active' => true,
        ]);

        $group = Group::query()->firstWhere('name', 'ИСП-101') ?? Group::create([
            'name' => 'ИСП-101',
            'specialty' => 'Инструментальное исполнительство',
            'year_start' => 2026,
        ]);

        $student = Student::create([
            'group_id' => $group->id,
            'last_name' => $lastName,
            'first_name' => 'Иван',
            'status' => 'active',
            'is_resident' => true,
        ]);

        DormPlacement::create([
            'dorm_room_id' => $room->id,
            'student_id' => $student->id,
            'moved_in_at' => '2026-09-01',
        ]);

        return $student;
    }

    private function pass(Student $student, string $at, string $direction, string $result = AccessEvent::RESULT_ALLOWED): void
    {
        $point = AccessPoint::query()->firstWhere('code', 'SER277-MAIN');

        AccessEvent::create([
            'access_point_id' => $point->id,
            'entity_type' => 'student',
            'entity_id' => $student->id,
            'direction' => $direction,
            'event_time' => $at,
            'result' => $result,
        ]);
    }

    private function warden(): User
    {
        return $this->userWith(['dorm.absences.view', 'dorm.leaves.manage']);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['code' => 'night_'.substr(md5(json_encode($permissions)), 0, 12)],
            ['name' => 'Ночи '.substr(md5(json_encode($permissions)), 0, 8), 'description' => 'Test role'],
        );

        foreach ($permissions as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => 'Test', 'description' => $code, 'system' => true, 'active' => true],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);

        return $user;
    }
}
