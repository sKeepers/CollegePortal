<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\DormPlacement;
use App\Models\DormRoom;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Печатные списки и отчёты общежития.
 *
 * Список проживающих вывешивают по этажам, лист печатают на дверь, отчёт
 * заселённости спрашивают заместитель и директор.
 */
class DormReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_resident_list_is_grouped_by_floor_and_carries_the_phone(): void
    {
        $student = $this->resident('Живущий', '201', 2, '+7 900 000-00-01');
        $this->withApiAuth($this->warden());

        $data = $this->getJson('/api/dorm/reports/residents')->assertOk()->json('data');

        $this->assertCount(1, $data['floors']);
        $floor = $data['floors'][0];
        $this->assertSame(2, $floor['floor']);

        $person = $floor['rooms'][0]['people'][0];
        $this->assertSame($student->id, $person['student_id']);
        $this->assertSame('+7 900 000-00-01', $person['phone']);
        // Курс не хранится, а считается из года набора.
        $this->assertNotNull($person['course']);
        $this->assertNotNull($person['group']);
    }

    public function test_the_phone_falls_back_to_the_person_card(): void
    {
        // Коменданту звонить, а не выяснять, в какой карточке записан номер.
        $person = Person::create(['last_name' => 'Безтелефонный', 'first_name' => 'Иван', 'status' => 'active', 'phone' => '+7 900 000-00-02']);
        $student = $this->resident('Безтелефонный', '202', 2, null);
        $student->forceFill(['person_id' => $person->id])->save();

        $this->withApiAuth($this->warden());

        $data = $this->getJson('/api/dorm/reports/residents')->assertOk()->json('data');
        $phones = collect($data['floors'])->flatMap(fn ($floor) => collect($floor['rooms'])->flatMap->people)->pluck('phone');

        $this->assertTrue($phones->contains('+7 900 000-00-02'));
    }

    public function test_an_empty_room_still_appears_in_the_list(): void
    {
        $this->room('301', 3);
        $this->withApiAuth($this->warden());

        $data = $this->getJson('/api/dorm/reports/residents')->assertOk()->json('data');
        $room = $data['floors'][0]['rooms'][0];

        // Пустая комната на листе нужна: по нему и заселяют.
        $this->assertSame('301', $room['number']);
        $this->assertSame(0, $room['occupied']);
        $this->assertSame([], $room['people']);
    }

    public function test_the_occupancy_report_counts_places_and_movement(): void
    {
        $stayed = $this->resident('Оставшийся', '401', 4);
        $left = $this->resident('Уехавший', '402', 4);

        DormPlacement::query()->where('student_id', $left->id)->update(['moved_out_at' => '2026-09-20']);

        $this->withApiAuth($this->warden());

        $data = $this->getJson('/api/dorm/reports/occupancy?from=2026-09-01&to=2026-09-30')->assertOk()->json('data');

        $this->assertSame(2, $data['totals']['moved_in']);
        $this->assertSame(1, $data['totals']['moved_out']);
        // Выехавший освободил место: занято считается по действующим.
        $this->assertSame(1, $data['totals']['occupied']);
        $this->assertSame($data['totals']['capacity'] - 1, $data['totals']['free']);
        $this->assertNotEmpty($data['by_date']);
        $this->assertNotNull($stayed->id);
    }

    public function test_the_period_must_not_run_backwards(): void
    {
        $this->withApiAuth($this->warden());

        $this->getJson('/api/dorm/reports/occupancy?from=2026-09-30&to=2026-09-01')
            ->assertStatus(422)
            ->assertJsonPath('errors.to.0', 'Конец периода раньше начала.');
    }

    public function test_reports_need_the_placement_permission(): void
    {
        $this->withApiAuth($this->userWith(['dorm.rooms.view'], 'norights'));

        $this->getJson('/api/dorm/reports/residents')->assertForbidden();
        $this->getJson('/api/dorm/reports/occupancy?from=2026-09-01&to=2026-09-30')->assertForbidden();
    }

    private function room(string $number, int $floor): DormRoom
    {
        $building = Building::query()->firstWhere('code', 'SER277') ?? Building::create([
            'name' => 'Общежитие на Серова, 277', 'code' => 'SER277', 'is_active' => true,
        ]);

        return DormRoom::query()->firstWhere('number', $number) ?? DormRoom::create([
            'building_id' => $building->id, 'number' => $number, 'floor' => $floor,
            'capacity' => 3, 'is_active' => true,
        ]);
    }

    private function resident(string $lastName, string $number, int $floor, ?string $phone = null): Student
    {
        $group = Group::query()->firstWhere('name', 'ИСП-101') ?? Group::create([
            'name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'year_start' => 2026,
        ]);

        $student = Student::create([
            'group_id' => $group->id, 'last_name' => $lastName, 'first_name' => 'Иван',
            'status' => 'active', 'is_resident' => true, 'phone' => $phone,
        ]);

        DormPlacement::create([
            'dorm_room_id' => $this->room($number, $floor)->id,
            'student_id' => $student->id,
            'moved_in_at' => '2026-09-01',
        ]);

        return $student;
    }

    private function warden(): User
    {
        return $this->userWith(['dorm.rooms.view', 'dorm.placements.view'], 'warden');
    }

    private function userWith(array $permissions, string $suffix): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['code' => 'report_'.$suffix],
            ['name' => 'Отчёты '.$suffix, 'description' => 'Test role'],
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
