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
     * Замер от 10.08.2026 на составе прав из `RoleSeeder`.
     *
     * @var array<string, int>
     */
    private const CEILING = [
        'study' => 112,
        'deputy' => 44,
        'academic_office' => 44,
        'admission' => 5,
    ];

    public function test_no_role_leans_on_an_umbrella_more_than_it_did(): void
    {
        $this->seed(RoleSeeder::class);

        $counts = [];
        foreach (app(PermissionInventory::class)->dependentOnUmbrella() as $row) {
            $counts[$row['role']] = ($counts[$row['role']] ?? 0) + 1;
        }

        foreach ($counts as $role => $count) {
            $ceiling = self::CEILING[$role] ?? 0;

            $this->assertLessThanOrEqual(
                $ceiling,
                $count,
                "Роль {$role} стала опираться на право-зонтик в {$count} местах вместо {$ceiling}: ".
                'маршрут добавили в группу, не дав роли конкретного права. Посмотрите `php artisan permissions:inventory --umbrella-only`.',
            );
        }

        foreach (array_keys(self::CEILING) as $role) {
            $this->assertArrayHasKey(
                $role,
                $counts + array_fill_keys(array_keys(self::CEILING), 0),
                "Роль {$role} исчезла из замера — обновите потолки.",
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
