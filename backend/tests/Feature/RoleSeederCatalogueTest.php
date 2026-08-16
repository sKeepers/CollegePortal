<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Каталог прав и наборы ролей обязаны сходиться.
 *
 * Право, выданное роли по коду, которого нет в каталоге, раньше просто не
 * выдавалось: `whereIn` возвращал меньше строк, `sync()` раскладывал что нашлось,
 * установка проходила зелёной. Так уже терялись `gate.points.manage` и
 * `students.bulk_accounts` — обнаружились они по жалобе на `403` спустя время.
 */
class RoleSeederCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_code_granted_to_a_role_exists_in_the_catalogue(): void
    {
        // Сидер сам роняет установку на неизвестном коде, поэтому «прошёл» здесь
        // и значит «в наборах ролей нет ни одной опечатки».
        $this->seed(RoleSeeder::class);

        $this->assertGreaterThan(0, Permission::query()->count());
        $this->assertGreaterThan(0, Role::query()->count());
    }

    public function test_an_unknown_code_fails_loudly_instead_of_being_dropped(): void
    {
        $this->seed(RoleSeeder::class);

        // Каталог сидер пересоздаёт сам, поэтому удалить право и запустить его
        // заново — не тот опыт: оно вернётся. Проверяем саму защиту.
        $ids = new \ReflectionMethod(RoleSeeder::class, 'ids');
        $ids->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/students\.veiw/');

        $ids->invoke(new RoleSeeder(), ['students.view', 'students.veiw']);
    }

    public function test_the_baseline_permission_reaches_every_role(): void
    {
        $this->seed(RoleSeeder::class);

        $withoutBaseline = Role::query()
            ->whereDoesntHave('permissions', fn ($query) => $query->where('code', 'reference.view'))
            ->pluck('code')
            ->all();

        $this->assertSame([], $withoutBaseline, 'Чтение справочников — базовое право, его получает каждая роль.');
    }
}
