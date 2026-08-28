<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\DormPlacement;
use App\Models\DormRoom;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Общежитие: комнаты и заселение.
 *
 * Проверяется то, что расходится молча: вместимость против действующих
 * заселений, история переселений и признак «проживающий» в карточке студента.
 */
class DormPlacementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_placing_a_student_marks_the_card_as_resident(): void
    {
        $this->withApiAuth($this->warden());
        $room = $this->room(capacity: 3);
        $student = $this->student();

        $this->postJson('/api/dorm/placements', [
            'student_id' => $student->id,
            'dorm_room_id' => $room->id,
            'moved_in_at' => '2026-09-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.room.number', $room->number);

        // Признак живёт в карточке студента и обязан следовать за фактом:
        // разойдясь, они дальше врут молча, а списки строятся по признаку.
        $this->assertTrue($student->fresh()->is_resident);
    }

    public function test_a_full_room_takes_nobody_else(): void
    {
        $this->withApiAuth($this->warden());
        $room = $this->room(capacity: 1);

        $this->postJson('/api/dorm/placements', [
            'student_id' => $this->student()->id,
            'dorm_room_id' => $room->id,
            'moved_in_at' => '2026-09-01',
        ])->assertCreated();

        $this->postJson('/api/dorm/placements', [
            'student_id' => $this->student('Второй')->id,
            'dorm_room_id' => $room->id,
            'moved_in_at' => '2026-09-01',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.dorm_room_id.0', "В комнате {$room->number} мест нет: вместимость 1, живут 1.");
    }

    public function test_a_room_without_capacity_takes_nobody(): void
    {
        $this->withApiAuth($this->warden());
        // Ноль здесь не выдуман: это умолчание колонки `capacity` в базе, и
        // правила ввода комнаты его разрешают (`min:0`). Комната, заведённая
        // наспех, оказывается именно такой.
        $room = $this->room(capacity: 0, number: '404');

        $this->postJson('/api/dorm/placements', [
            'student_id' => $this->student()->id,
            'dorm_room_id' => $room->id,
            'moved_in_at' => '2026-09-01',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.dorm_room_id.0', 'В комнате 404 мест нет: вместимость 0. Если в ней живут, укажите вместимость в карточке комнаты.');

        $this->assertDatabaseCount('dorm_placements', 0);
    }

    public function test_a_room_without_capacity_is_never_offered_as_free(): void
    {
        $this->withApiAuth($this->warden());
        // Тот же ноль с другой стороны. Отбор «только со свободными местами»
        // считал такую комнату занятой ещё до правки, а заселение пускало в
        // неё кого угодно: две части одной возможности отвечали по-разному на
        // один вопрос. Проверяем обе сразу, иначе они снова разойдутся.
        $this->room(capacity: 0, number: '404');
        $this->room(capacity: 2, number: '405');

        $numbers = collect($this->getJson('/api/dorm/rooms?only_free=1')->assertOk()->json('data'))
            ->pluck('number')
            ->all();

        $this->assertSame(['405'], $numbers);
    }

    public function test_relocating_into_a_room_without_capacity_is_refused(): void
    {
        $this->withApiAuth($this->warden());
        // Переселение идёт мимо `place()` своим путём и проверку вместимости
        // делает отдельно. Дыра в ней была та же, и закрыть её надо было тем
        // же местом — этот тест краснеет, если проверку починили только в
        // одном из двух.
        $from = $this->room(capacity: 2, number: '406');
        $to = $this->room(capacity: 0, number: '407');
        $student = $this->student();

        $this->postJson('/api/dorm/placements', [
            'student_id' => $student->id,
            'dorm_room_id' => $from->id,
            'moved_in_at' => '2026-09-01',
        ])->assertCreated();

        $this->postJson('/api/dorm/placements/relocate', [
            'student_id' => $student->id,
            'dorm_room_id' => $to->id,
            'moved_in_at' => '2026-09-02',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.dorm_room_id.0', 'В комнате 407 мест нет: вместимость 0. Если в ней живут, укажите вместимость в карточке комнаты.');

        // И студент остался там, где был: неудачное переселение не должно
        // закрывать прежнее заселение.
        $this->assertDatabaseHas('dorm_placements', [
            'student_id' => $student->id,
            'dorm_room_id' => $from->id,
            'moved_out_at' => null,
        ]);
    }

    public function test_a_student_is_not_placed_twice(): void
    {
        $this->withApiAuth($this->warden());
        $first = $this->room(capacity: 2, number: '101');
        $second = $this->room(capacity: 2, number: '102');
        $student = $this->student();

        $this->postJson('/api/dorm/placements', [
            'student_id' => $student->id,
            'dorm_room_id' => $first->id,
            'moved_in_at' => '2026-09-01',
        ])->assertCreated();

        // Второе заселение означало бы, что из первой комнаты его не выселили,
        // и обе комнаты считают его своим.
        $this->postJson('/api/dorm/placements', [
            'student_id' => $student->id,
            'dorm_room_id' => $second->id,
            'moved_in_at' => '2026-09-02',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.student_id.0', 'Студент уже заселён в комнату 101. Переселите его — тогда прежнее заселение закроется, а история останется.');
    }

    public function test_relocation_keeps_the_history(): void
    {
        $this->withApiAuth($this->warden());
        $first = $this->room(capacity: 2, number: '201');
        $second = $this->room(capacity: 2, number: '202');
        $student = $this->student();

        $this->postJson('/api/dorm/placements', [
            'student_id' => $student->id,
            'dorm_room_id' => $first->id,
            'moved_in_at' => '2026-09-01',
        ])->assertCreated();

        $this->postJson('/api/dorm/placements/relocate', [
            'student_id' => $student->id,
            'dorm_room_id' => $second->id,
            'moved_in_at' => '2026-10-01',
        ])
            // 201, а не 200: переселение и правда создаёт новое заселение, и
            // Laravel отвечает «создано», когда ресурс обёрнут вокруг только
            // что созданной записи.
            ->assertCreated()
            ->assertJsonPath('data.room.number', '202')
            ->assertJsonPath('data.is_open', true);

        // Переселение — не правка строки: прежнее заселение закрывается, и
        // история переселений остаётся. Она нужна заместителю.
        $history = DormPlacement::query()->where('student_id', $student->id)->orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertSame('2026-10-01', $history->first()->moved_out_at?->toDateString());
        $this->assertNull($history->last()->moved_out_at);
        $this->assertTrue($student->fresh()->is_resident);
    }

    public function test_moving_out_clears_the_resident_flag(): void
    {
        $this->withApiAuth($this->warden());
        $room = $this->room(capacity: 2);
        $student = $this->student();

        $this->postJson('/api/dorm/placements', [
            'student_id' => $student->id,
            'dorm_room_id' => $room->id,
            'moved_in_at' => '2026-09-01',
        ])->assertCreated();

        $this->postJson('/api/dorm/placements/move-out', [
            'student_id' => $student->id,
            'moved_out_at' => '2027-06-30',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_open', false);

        $this->assertFalse($student->fresh()->is_resident);
    }

    public function test_moving_out_before_moving_in_is_refused(): void
    {
        $this->withApiAuth($this->warden());
        $room = $this->room(capacity: 2);
        $student = $this->student();

        $this->postJson('/api/dorm/placements', [
            'student_id' => $student->id,
            'dorm_room_id' => $room->id,
            'moved_in_at' => '2026-09-01',
        ])->assertCreated();

        $this->postJson('/api/dorm/placements/move-out', [
            'student_id' => $student->id,
            'moved_out_at' => '2026-08-01',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.moved_out_at.0', 'Дата выселения раньше даты заселения.');
    }

    public function test_the_free_room_filter_counts_live_placements(): void
    {
        $this->withApiAuth($this->warden());
        $full = $this->room(capacity: 1, number: '301');
        $this->room(capacity: 2, number: '302');

        $this->postJson('/api/dorm/placements', [
            'student_id' => $this->student()->id,
            'dorm_room_id' => $full->id,
            'moved_in_at' => '2026-09-01',
        ])->assertCreated();

        $rooms = $this->getJson('/api/dorm/rooms?only_free=1')->assertOk()->json('data');

        $this->assertCount(1, $rooms);
        $this->assertSame('302', $rooms[0]['number']);
        $this->assertSame(2, $rooms[0]['free']);
    }

    public function test_the_deputy_sees_placements_but_does_not_change_them(): void
    {
        $this->withApiAuth($this->userWith(['dorm.placements.view', 'dorm.rooms.view']));

        $this->getJson('/api/dorm/placements')->assertOk();
        $this->postJson('/api/dorm/placements', [
            'student_id' => 1,
            'dorm_room_id' => 1,
            'moved_in_at' => '2026-09-01',
        ])->assertForbidden();
    }

    public function test_the_warden_never_sees_conduct_or_the_social_passport(): void
    {
        $this->seed(RoleSeeder::class);

        $warden = Role::query()->where('code', 'dorm_warden')->first();
        $deputy = Role::query()->where('code', 'deputy_upbringing')->first();

        $this->assertNotNull($warden, 'Роль коменданта общежития не заведена');
        $this->assertNotNull($deputy, 'Роль заместителя по воспитательной работе не заведена');

        // Самые чувствительные данные во всём портале: коменданту они не видны
        // ни в каком виде, и это разграничение — половина смысла двух ролей.
        foreach (['dorm.conduct.view', 'dorm.conduct.manage', 'dorm.social.view', 'dorm.social.manage'] as $code) {
            $this->assertFalse($warden->permissions()->where('code', $code)->exists(), "Коменданту досталось {$code}");
            $this->assertTrue($deputy->permissions()->where('code', $code)->exists(), "Заместителю не выдано {$code}");
        }

        // И обратно: оплата — работа коменданта, заместителю её не дают.
        $this->assertTrue($warden->permissions()->where('code', 'dorm.payments.manage')->exists());
        $this->assertFalse($deputy->permissions()->where('code', 'dorm.payments.manage')->exists());
    }

    private function room(int $capacity, string $number = '100'): DormRoom
    {
        $building = Building::query()->firstWhere('code', 'DORM') ?? Building::create([
            'name' => 'Общежитие',
            'code' => 'DORM',
            'is_active' => true,
        ]);

        return DormRoom::create([
            'building_id' => $building->id,
            'number' => $number,
            'floor' => 1,
            'capacity' => $capacity,
            'kind' => DormRoom::KIND_REGULAR,
            'is_active' => true,
        ]);
    }

    private function student(string $lastName = 'Проживающий'): Student
    {
        $group = Group::query()->firstWhere('name', 'ИСП-101') ?? Group::create([
            'name' => 'ИСП-101',
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);

        return Student::create([
            'group_id' => $group->id,
            'last_name' => $lastName,
            'first_name' => 'Иван',
            'status' => 'active',
        ]);
    }

    private function warden(): User
    {
        return $this->userWith([
            'dorm.rooms.view', 'dorm.rooms.manage',
            'dorm.placements.view', 'dorm.placements.manage',
        ]);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['code' => 'dorm_'.substr(md5(json_encode($permissions)), 0, 12)],
            ['name' => 'Общежитие '.substr(md5(json_encode($permissions)), 0, 8), 'description' => 'Test role'],
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
