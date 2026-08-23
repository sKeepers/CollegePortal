<?php

namespace Tests\Feature;

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
use App\Services\DormPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сводки «что сегодня».
 *
 * Главное, что здесь проверяется, — не числа, а честность: ноль показывается
 * только тогда, когда он посчитан, и каждый блок закрыт своим правом.
 */
class DormTodaySummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_uncounted_night_says_so_instead_of_showing_zero(): void
    {
        $this->resident();
        $this->withApiAuth($this->warden());

        $this->getJson('/api/dorm/today')
            ->assertOk()
            // Ночь никто не считал. «Все на месте» здесь было бы враньём.
            ->assertJsonPath('data.night.calculated', false)
            ->assertJsonPath('data.night.count', null);
    }

    public function test_a_counted_night_without_absences_shows_a_real_zero(): void
    {
        $this->resident();
        Setting::query()->updateOrCreate(
            ['group' => 'dorm', 'key' => 'absences_calculated_through'],
            ['value' => now()->toDateString(), 'type' => 'string', 'is_public' => false],
        );

        $this->withApiAuth($this->warden());

        $this->getJson('/api/dorm/today')
            ->assertOk()
            ->assertJsonPath('data.night.calculated', true)
            ->assertJsonPath('data.night.count', 0);
    }

    public function test_the_summary_shows_free_places_and_overdue_payment(): void
    {
        $student = $this->resident();
        app(DormPaymentService::class)->record($student, now()->subDays(10)->toDateString());

        $this->withApiAuth($this->warden());

        $response = $this->getJson('/api/dorm/today')->assertOk();

        $response->assertJsonPath('data.places.capacity', 4);
        $response->assertJsonPath('data.places.occupied', 1);
        $response->assertJsonPath('data.places.free', 3);
        $response->assertJsonPath('data.payments.overdue', 1);
    }

    public function test_the_absent_person_reaches_the_summary(): void
    {
        $student = $this->resident();
        $night = now()->subDay()->toDateString();

        DormAbsence::create(['student_id' => $student->id, 'night_of' => $night, 'left_at' => $night.' 21:00:00']);
        Setting::query()->updateOrCreate(
            ['group' => 'dorm', 'key' => 'absences_calculated_through'],
            ['value' => $night, 'type' => 'string', 'is_public' => false],
        );

        $this->withApiAuth($this->warden());

        $this->getJson('/api/dorm/today')
            ->assertOk()
            ->assertJsonPath('data.night.count', 1)
            ->assertJsonPath('data.night.people.0.student_id', $student->id);
    }

    public function test_a_block_is_absent_when_its_permission_is(): void
    {
        $this->resident();
        // Заместитель по воспитательной работе видит места, ночь и
        // происшествия, но не оплату: она не его работа.
        $this->withApiAuth($this->userWith(['dorm.rooms.view', 'dorm.absences.view', 'dorm.incidents.view'], 'deputy'));

        $data = $this->getJson('/api/dorm/today')->assertOk()->json('data');

        $this->assertArrayHasKey('places', $data);
        $this->assertArrayHasKey('night', $data);
        $this->assertArrayNotHasKey('payments', $data);
    }

    public function test_the_two_summaries_do_not_mix(): void
    {
        $this->resident();

        // Комендант до сводки заместителя не доходит вовсе.
        $this->withApiAuth($this->warden());
        $this->getJson('/api/dorm/upbringing/today')->assertForbidden();

        // А заместитель в своей сводке видит свои блоки.
        $this->withApiAuth($this->userWith(['dorm.conduct.view', 'dorm.social.view'], 'upbringing'));
        $data = $this->getJson('/api/dorm/upbringing/today')->assertOk()->json('data');

        $this->assertArrayHasKey('conduct', $data);
        $this->assertArrayHasKey('social', $data);
    }

    private function resident(): Student
    {
        $building = Building::query()->firstWhere('code', 'SER277') ?? Building::create([
            'name' => 'Общежитие на Серова, 277', 'code' => 'SER277', 'is_active' => true,
        ]);

        $room = DormRoom::query()->firstWhere('number', '101') ?? DormRoom::create([
            'building_id' => $building->id, 'number' => '101', 'capacity' => 4, 'is_active' => true,
        ]);

        $group = Group::query()->firstWhere('name', 'ИСП-101') ?? Group::create([
            'name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'year_start' => 2026,
        ]);

        $student = Student::create([
            'group_id' => $group->id, 'last_name' => 'Сводкин', 'first_name' => 'Иван',
            'status' => 'active', 'is_resident' => true,
        ]);

        DormPlacement::create([
            'dorm_room_id' => $room->id, 'student_id' => $student->id, 'moved_in_at' => now()->subMonth()->toDateString(),
        ]);

        return $student;
    }

    private function warden(): User
    {
        return $this->userWith([
            'dorm.rooms.view', 'dorm.absences.view', 'dorm.payments.view', 'dorm.incidents.view',
        ], 'warden');
    }

    private function userWith(array $permissions, string $suffix): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['code' => 'today_'.$suffix],
            ['name' => 'Сводка '.$suffix, 'description' => 'Test role'],
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
