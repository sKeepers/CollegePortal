<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Право видеть своё есть у директора, приёмной комиссии и проходной.
 *
 * Раздел «Мой QR-пропуск» открывается правом `view_own_data`. У этих трёх ролей
 * его не было, поэтому свой собственный пропуск они не видели — при том что
 * пропуск им выдан и на проходной работает.
 *
 * Проверяются оба пути раздачи. Сидер выполняется только при установке, поэтому
 * право обязано приходить и миграцией — иначе на уже стоящий портал оно не
 * доедет. Обратное тоже верно: право, заведённое только миграцией, стёрлось бы
 * на новой установке, потому что сидер раздаёт права через `sync()`.
 */
class OwnPassPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['director', 'admission', 'security'];

    public function test_the_seeder_gives_the_three_roles_their_own_data(): void
    {
        $this->seed(RoleSeeder::class);

        foreach (self::ROLES as $code) {
            $role = Role::query()->where('code', $code)->firstOrFail();

            $this->assertTrue(
                $role->permissions()->where('code', 'view_own_data')->exists(),
                "Роль {$code} осталась без права видеть своё после сидера",
            );
        }
    }

    public function test_the_migration_gives_it_too_and_survives_a_second_run(): void
    {
        $this->seed(RoleSeeder::class);

        $permissionId = Permission::query()->where('code', 'view_own_data')->value('id');
        $roleIds = Role::query()->whereIn('code', self::ROLES)->pluck('id');

        // Возвращаем состояние, которое было на боевом сервере до правки.
        DB::table('permission_role')
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', $roleIds)
            ->delete();

        $this->assertSame(0, DB::table('permission_role')
            ->where('permission_id', $permissionId)->whereIn('role_id', $roleIds)->count());

        $migration = require database_path('migrations/2026_08_21_000003_director_admission_and_security_see_their_own_pass.php');
        $migration->up();
        $migration->up(); // повторный запуск не должен ничего ломать

        $this->assertSame(3, DB::table('permission_role')
            ->where('permission_id', $permissionId)->whereIn('role_id', $roleIds)->count());
    }
}
