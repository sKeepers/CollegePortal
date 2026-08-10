<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePermission;
use App\Models\Permission;
use App\Support\Permissions\PermissionInventory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `ARCH-001`: сколько доступа держится на праве-зонтике.
 *
 * Право-зонтик — `manage_dictionaries` и подобные — открывало роли целую группу
 * маршрутов, не требуя ни одного конкретного права. Шаг 1 это измерил, шаг 2
 * назвал недостающие права своими именами, шаг 3 объявил право у маршрута и
 * снял зонтики.
 *
 * **Потолок опущен до нуля и обратно не поднимается.** Ненулевой замер значит,
 * что зонтик вернулся: на маршрут, в сидер или в матрицу разрешений.
 */
class PermissionUmbrellaDependencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_role_reaches_anything_through_an_umbrella(): void
    {
        $this->seed(RoleSeeder::class);

        $inventory = app(PermissionInventory::class);

        // Пустой замер прошёл бы любую проверку ниже, поэтому сначала
        // убеждаемся, что мерить вообще было что.
        $this->assertNotEmpty($inventory->reachable(), 'Замер пуст: роли без прав или маршруты не загрузились.');

        $leaning = $inventory->dependentOnUmbrella();

        $this->assertSame([], $leaning, implode("\n", array_merge(
            ['Роли снова дотягиваются до маршрутов через право-зонтик:'],
            array_map(
                fn (array $row): string => $row['role'].' → '.$row['method'].' '.$row['uri'].' ('.implode(', ', $row['umbrella']).')',
                $leaning,
            ),
        )));
    }

    /**
     * Зонтик, оставшийся выданным роли, не виден нигде: ни в обходе, ни в
     * матрице разрешений — маршрутов, которые он открывает, больше нет. Но
     * стоит вернуть его на маршрут, и доступ откроется молча. Поэтому у ролей
     * его быть не должно вовсе.
     */
    public function test_no_role_holds_a_legacy_umbrella(): void
    {
        $this->seed(RoleSeeder::class);

        $held = Permission::query()
            ->whereIn('code', EnsurePermission::LEGACY_UMBRELLAS)
            ->with('roles')
            ->get()
            ->flatMap(fn (Permission $permission): array => $permission->roles
                ->map(fn ($role): string => $role->code.' → '.$permission->code)
                ->all())
            ->all();

        $this->assertSame([], $held, implode("\n", array_merge(
            ['Роли держат legacy-право-«зонтик» — выдайте конкретные права вместо него:'],
            $held,
        )));
    }

    public function test_the_inventory_counts_access_the_same_way_the_middleware_does(): void
    {
        $this->seed(RoleSeeder::class);

        $reachable = app(PermissionInventory::class)->reachable();

        $this->assertNotEmpty($reachable, 'Инвентаризация не нашла ни одного маршрута под проверкой права.');

        // Администратор проходит через Gate::before и в замер не попадает:
        // иначе он показал бы доступ всюду и спрятал бы зависимости.
        $this->assertEmpty(
            array_filter($reachable, fn (array $row): bool => $row['role'] === 'admin'),
            'Администратор попал в замер — он проходит мимо прав и портит картину.',
        );
    }
}
