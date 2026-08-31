<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Права двух ролей складываются, а не заменяют друг друга.
 *
 * Вопрос практический и с датой: в среду 2 сентября заселять в общежитие и
 * выдавать RFID-карты, возможно, будет **один человек**. Если права ролей
 * складываются, ему дают две роли — коменданта общежития и того, кто ведёт
 * карты; если нет, придётся заводить третью роль с объединённым набором, а это
 * лишняя сущность, которая потом разойдётся с исходными.
 *
 * Ответ — складываются, и здесь он закреплён поведением, а не чтением кода:
 * `User::hasPermission()` спрашивает и связь «многие ко многим» (`role_user`),
 * и одиночную `role_id`, объединяя их через «или». Прочесть это можно, но
 * прочтённое стареет; проверка переживёт правку.
 *
 * Проверяется **на настоящих правах области**, а не на выдуманных: комнаты
 * ведёт `dorm.rooms.manage`, карты — `rfid.cards.manage`, и в портале они
 * лежат у разных ролей.
 */
class TwoRolesAddUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_person_with_two_roles_gets_the_permissions_of_both(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($this->roleWith('dorm_rooms_only', ['dorm.rooms.view', 'dorm.rooms.manage'])->id);
        $user->roles()->attach($this->roleWith('cards_only', ['rfid.cards.view', 'rfid.cards.manage'])->id);

        $this->withApiAuth($user);

        // Обе двери открыты одному человеку: ни одна роль не «перебила» другую.
        $this->getJson('/api/dorm/rooms')->assertOk();
        $this->getJson('/api/rfid-cards')->assertOk();
    }

    public function test_neither_role_alone_opens_the_other_door(): void
    {
        // Обратная сторона: если бы любая роль открывала всё, первая проверка
        // проходила бы и при полностью сломанном разграничении.
        $commandant = User::factory()->create(['is_active' => true]);
        $commandant->roles()->attach($this->roleWith('dorm_rooms_only', ['dorm.rooms.view', 'dorm.rooms.manage'])->id);

        $this->withApiAuth($commandant);
        $this->getJson('/api/dorm/rooms')->assertOk();
        $this->getJson('/api/rfid-cards')->assertForbidden();
    }

    public function test_the_single_role_field_adds_up_with_the_pivot_too(): void
    {
        // В портале две связи: одиночная `users.role_id` и «многие ко многим»
        // `role_user`. Обе живые, и человек может получить право по любой из
        // них — значит проверять надо и стык, а не только пивот.
        $user = User::factory()->create([
            'is_active' => true,
            'role_id' => $this->roleWith('cards_only', ['rfid.cards.view', 'rfid.cards.manage'])->id,
        ]);
        $user->roles()->attach($this->roleWith('dorm_rooms_only', ['dorm.rooms.view', 'dorm.rooms.manage'])->id);

        $this->withApiAuth($user);

        $this->getJson('/api/rfid-cards')->assertOk();
        $this->getJson('/api/dorm/rooms')->assertOk();
    }

    /** @param array<int, string> $codes */
    private function roleWith(string $code, array $codes): Role
    {
        $role = Role::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'description' => 'Проба сложения прав'],
        );

        foreach ($codes as $permissionCode) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $permissionCode],
                ['name' => $permissionCode, 'module' => 'Test', 'description' => $permissionCode, 'system' => true, 'active' => true],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return $role;
    }
}
