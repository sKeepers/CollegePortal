<?php

namespace Tests\Feature;

use App\Support\Permissions\PermissionInventory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `ARCH-001`, шаг первый: сколько доступа держится на праве-зонтике.
 *
 * Право-зонтик — `manage_dictionaries` и подобные — открывает роли целую
 * группу маршрутов, не требуя ни одного конкретного права. Снять его вслепую
 * нельзя: уточнение таблицы однажды уже сломало массовый экспорт у директора.
 * Поэтому сначала измеряем, а тест сторожит измерение.
 *
 * **Потолки только опускаются.** Каждый маршрут, получивший своё право,
 * уменьшает число; выросло — значит в группу добавили маршрут, не дав роли
 * конкретного права, и мы снова копим долг вместо того, чтобы его отдавать.
 */
class PermissionUmbrellaDependencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Замер от 10.08.2026, после того как роли получили свои права.
     *
     * Осталось только то, что владелец решил забрать: у «Учебной части» это
     * правка справочников (18 маршрутов), раздел администрирования (17) и три
     * удаления. Когда зонтик снимут, этот доступ и должен пропасть.
     *
     * @var array<string, int>
     */
    private const CEILING = ['study' => 38];

    public function test_no_role_leans_on_an_umbrella_more_than_it_did(): void
    {
        $this->seed(RoleSeeder::class);

        $inventory = app(PermissionInventory::class);
        $counts = [];

        foreach ($inventory->dependentOnUmbrella() as $row) {
            $counts[$row['role']] = ($counts[$row['role']] ?? 0) + 1;
        }

        // Пустой замер прошёл бы любую проверку ниже, поэтому сначала
        // убеждаемся, что мерить вообще было что.
        $this->assertNotEmpty($inventory->reachable(), 'Замер пуст: роли без прав или маршруты не загрузились.');

        foreach ($counts as $role => $count) {
            $ceiling = self::CEILING[$role] ?? 0;

            $this->assertLessThanOrEqual(
                $ceiling,
                $count,
                "Роль {$role} опирается на право-зонтик в {$count} местах вместо {$ceiling}: ".
                'маршрут добавили в группу, не дав роли конкретного права. Посмотрите `php artisan permissions:inventory --umbrella-only`.',
            );
        }
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
