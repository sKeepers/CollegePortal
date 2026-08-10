<?php

namespace App\Console\Commands;

use App\Support\Permissions\PermissionInventory;
use Illuminate\Console\Command;

/**
 * Первый шаг `ARCH-001`: посчитать, кто до чего дотягивается и каким правом.
 *
 * Пока не видно, кто держится на праве-зонтике, снимать его нельзя — таблица
 * прав только сужает доступ, и однажды уточнение уже сломало массовый экспорт
 * у директора.
 */
class PermissionInventoryCommand extends Command
{
    protected $signature = 'permissions:inventory {--umbrella-only : показать только то, что держится на праве-зонтике} {--json}';

    protected $description = 'Показать, какие роли до каких маршрутов дотягиваются и каким правом.';

    public function handle(PermissionInventory $inventory): int
    {
        $rows = $this->option('umbrella-only') ? $inventory->dependentOnUmbrella() : $inventory->reachable();

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Роль', 'Метод', 'Маршрут', 'Чем проходит', 'Конкретные права', 'Зонтики'],
            array_map(fn (array $row): array => [
                $row['role'],
                $row['method'],
                $row['uri'],
                $row['via'] === 'umbrella' ? 'только зонтик' : 'конкретное право',
                implode(', ', $row['concrete']) ?: '—',
                implode(', ', $row['umbrella']) ?: '—',
            ], $rows),
        );

        $umbrella = count(array_filter($rows, fn (array $row): bool => $row['via'] === 'umbrella'));
        $this->newLine();
        $this->line('Всего пар «роль — маршрут»: '.count($rows).', из них держатся только на зонтике: '.$umbrella.'.');

        return self::SUCCESS;
    }
}
