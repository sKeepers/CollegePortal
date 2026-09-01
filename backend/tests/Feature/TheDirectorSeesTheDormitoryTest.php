<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\DormRoom;
use App\Models\Group;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Директор видит общежитие и ничего в нём не делает.
 *
 * Владелец 01.09.2026 назвал троих, кому нужен раздел: комендант общежития,
 * заместитель по воспитательной работе и директор. У первых двоих права были, у
 * директора не было ни одного.
 *
 * Сторож стоит на обеих сторонах нарочно. Прямая — директор действительно
 * получил просмотр, и получил его **из миграции**: набор здесь берётся после
 * `RefreshDatabase`, то есть после одних миграций, без сидера — ровно так, как
 * выглядит установленный портал после `installer/update.sh`. Встречная —
 * коменданту общежития ничего не сузили: самая вероятная ошибка такой правки не
 * в том, что кому-то не хватит права, а в том, что у кого-то оно пропадёт, и на
 * стенде этого никто не заметит.
 */
class TheDirectorSeesTheDormitoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_director_sees_the_rooms_and_who_lives_in_them(): void
    {
        $this->withApiAuth($this->withRole('director'));

        $this->getJson('/api/dorm/rooms')->assertOk();
        $this->getJson('/api/dorm/placements')->assertOk();
        $this->getJson('/api/dorm/today')->assertOk();
    }

    public function test_the_director_does_not_settle_anybody(): void
    {
        // Заселяет комендант. Набор директора по всем модулям устроен так:
        // просмотр — да, действие — у того, кто его выполняет.
        $this->withApiAuth($this->withRole('director'));

        $this->postJson('/api/dorm/placements', [
            'student_id' => $this->student()->id,
            'dorm_room_id' => $this->room()->id,
            'moved_in_at' => '2026-09-01',
        ])->assertForbidden();

        $this->postJson('/api/dorm/rooms', [
            'building_id' => $this->dorm()->id,
            'number' => '999',
            'floor' => 9,
            'capacity' => 2,
        ])->assertForbidden();
    }

    public function test_the_warden_still_settles(): void
    {
        // Встречная проверка. Без неё правка, отобравшая право у коменданта,
        // прошла бы зелёной: первый тест смотрит только на директора.
        $this->withApiAuth($this->withRole('dorm_warden'));

        $this->postJson('/api/dorm/placements', [
            'student_id' => $this->student()->id,
            'dorm_room_id' => $this->room()->id,
            'moved_in_at' => '2026-09-01',
        ])->assertCreated();
    }

    private function withRole(string $code): User
    {
        $role = Role::query()->firstWhere('code', $code);

        $this->assertNotNull($role, "роль {$code} обязана приходить миграцией");

        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function dorm(): Building
    {
        return Building::query()->firstWhere('code', 'SER277') ?? Building::create([
            'name' => 'Общежитие на Серова, 277',
            'code' => 'SER277',
            'is_active' => true,
        ]);
    }

    private function room(): DormRoom
    {
        return DormRoom::query()->firstWhere('number', '201') ?? DormRoom::create([
            'building_id' => $this->dorm()->id,
            'number' => '201',
            'floor' => 2,
            'capacity' => 3,
            'kind' => DormRoom::KIND_REGULAR,
            'is_active' => true,
        ]);
    }

    private function student(): Student
    {
        $group = Group::query()->firstWhere('name', 'ИСП-101') ?? Group::create([
            'name' => 'ИСП-101',
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);

        return Student::create([
            'group_id' => $group->id,
            'last_name' => 'Проживающий',
            'first_name' => 'Иван',
            'status' => 'active',
        ]);
    }
}
