<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Роль обязана приходить миграцией, а не только сидером.
 *
 * `installer/update.sh` выполняет одни миграции, поэтому роль, добавленную в
 * `RoleSeeder` после установки, обновлённая система не получает вовсе. Так и
 * вышло: `study_records` пришлось поднимать на боевом сервере руками 17.08.2026.
 *
 * Здесь ни один сидер не вызывается до тех пор, пока это не сказано явно:
 * `RefreshDatabase` гоняет только миграции — ровно то, что делает обновление.
 */
class RolesArriveByMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_alone_bring_every_role(): void
    {
        $codes = Role::query()->pluck('code')->all();

        $this->assertNotEmpty($codes, 'Обновлённая система осталась бы вообще без ролей.');
        $this->assertContains('study_records', $codes);
    }

    public function test_the_seeder_adds_no_role_the_migrations_missed(): void
    {
        $beforeSeeding = Role::query()->orderBy('code')->pluck('code')->all();

        $this->seed(RoleSeeder::class);

        $afterSeeding = Role::query()->orderBy('code')->pluck('code')->all();

        $this->assertSame(
            $afterSeeding,
            $beforeSeeding,
            'Роль, которую заводит только сидер, не доедет до уже установленной системы: заведите её ещё и миграцией.',
        );
    }

    /**
     * Здесь каталог состоит из одних миграционных прав — сидер не выполнялся, —
     * поэтому роль получает пересечение своего набора с тем, что есть. На
     * настоящей установленной системе каталог полный, и роль получает набор
     * целиком: проверено на DEV, где `study_records` вернулась со всеми 43
     * правами. Тест закрепляет то, что гарантировано и без сидера.
     */
    public function test_a_role_brought_by_migration_carries_the_permissions_that_exist(): void
    {
        $role = Role::query()->where('code', 'study_records')->firstOrFail();

        $codes = $role->permissions()->pluck('code')->all();

        // Роль без прав хуже отсутствующей: она есть в списке назначения, а
        // человек с ней упирается в 403 на каждом экране.
        $this->assertNotEmpty($codes);
        $this->assertContains('reference.view', $codes);
        $this->assertContains('trash.request', $codes);
    }

    public function test_running_the_seeder_does_not_duplicate_a_role(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertSame(1, Role::query()->where('code', 'study_records')->count());
    }
}
