<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Пункт меню не обещает того, чего маршрут не пускает.
 *
 * Пункт и его маршрут перечисляют роли **дважды**, в разных файлах, и разойтись
 * они могут молча: пункт виден, человек жмёт — «Доступ запрещён». Ни сборка, ни
 * прогон этого не видели, потому что оба файла по отдельности правильны.
 *
 * Замер 01.09.2026: во всём портале такое расхождение было **одно** — «Отчеты по
 * проходам» обещались отделу кадров в меню (`roles: admin, security, hr`), а
 * маршрут пускал только `admin` и `security`. Право `gate.reports` у кадров при
 * этом было, и сервер отчёты им отдавал: закрыт был ровно экран. Владелец
 * попросил открыть кадрам отчёты — и починкой оказалась одна строка, а не выдача
 * права.
 *
 * Сторож смотрит **в одну сторону** нарочно. Пункт, показанный **уже** маршрута,
 * — это не беда: так прячут раздел от того, кому он не нужен, оставляя адрес
 * рабочим. Беда — обещание, которого не выполняют.
 */
class MenuDoesNotPromiseWhatTheRouteRefusesTest extends TestCase
{
    public function test_no_menu_item_promises_a_role_its_route_refuses(): void
    {
        $menu = $this->file('layouts/AppLayout.vue');
        $routes = $this->file('router/routes.js');

        if ($menu === null || $routes === null) {
            $this->markTestSkipped('фронтенд рядом не смонтирован');
        }

        $routeRoles = $this->routeRoles($routes);
        $broken = [];

        foreach ($this->menuItems($menu) as $path => $roles) {
            // Пункт без ролей ничего лишнего не обещает: его закрывает право.
            if ($roles === null || ! array_key_exists($path, $routeRoles)) {
                continue;
            }

            // Маршрут без ролей пускает всех, у кого есть право, — шире меню.
            if ($routeRoles[$path] === null) {
                continue;
            }

            $promised = array_diff($roles, $routeRoles[$path]);

            if ($promised !== []) {
                $broken[] = $path.': меню обещает '.implode(', ', $promised).', маршрут пускает '.implode(', ', $routeRoles[$path]);
            }
        }

        $this->assertSame([], $broken, "пункт меню виден, а экран за ним закрыт:\n".implode("\n", $broken));
    }

    /** @return array<string, array<int, string>|null> */
    private function menuItems(string $menu): array
    {
        $items = [];

        foreach (explode("\n", $menu) as $line) {
            if (! preg_match("~to:\s*'(/[^']+)'~", $line, $to)) {
                continue;
            }

            $items[$to[1]] = $this->roles($line);
        }

        return $items;
    }

    /** @return array<string, array<int, string>|null> */
    private function routeRoles(string $routes): array
    {
        $found = [];

        // Путь и его `meta` идут парой внутри одного объекта маршрута.
        preg_match_all("~\{\s*path:\s*'([^']+)',.*?meta:\s*\{([^}]*)\}~s", $routes, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $found['/'.ltrim($match[1], '/')] = $this->roles($match[2]);
        }

        return $found;
    }

    /** @return array<int, string>|null */
    private function roles(string $text): ?array
    {
        if (! preg_match("~roles:\s*\[([^\]]*)\]~", $text, $match)) {
            return null;
        }

        preg_match_all("~'([^']+)'~", $match[1], $roles);

        return $roles[1];
    }

    private function file(string $path): ?string
    {
        $full = base_path('../frontend/src/'.$path);

        return is_readable($full) ? (string) file_get_contents($full) : null;
    }
}
