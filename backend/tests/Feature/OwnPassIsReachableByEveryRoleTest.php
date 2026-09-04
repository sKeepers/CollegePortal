<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Роль, которой положен свой пропуск, до него доходит.
 *
 * У пути к экрану **два** сторожа, и они в разных файлах: право у пункта меню
 * (`AppLayout.vue`) и список путей роли (`roleNavigation.js`). Список решает
 * **раньше** права: роль с правом, но без пути, не видит пункта в меню и
 * получает «Доступ запрещён» на прямом заходе — при том что сервер экран
 * отдаёт. Разойтись эти два файла могут молча: каждый по отдельности правилен.
 *
 * Замер 03.09.2026 17:32 UTC на стенде: право `view_own_data` есть у **всех
 * шестнадцати** ролей, а `/identity/my-pass` в списке путей приёмной комиссии
 * не было — пункта «Мой QR-пропуск» в её меню нет вовсе, прямой заход уводит на
 * `/forbidden`. Это пункт 17 обязательного минимума приёмки («свой пропуск у
 * любой роли»), и на этой роли он не проходил.
 *
 * Сторож смотрит **только на свой пропуск**, а не на все расхождения списка с
 * правами: таких в портале ещё девять, и какой из двух источников главный —
 * незакрытый вопрос владельцу. Здесь утверждается ровно одно и то, что решено:
 * свой пропуск открыт каждому, у кого на него есть право.
 */
class OwnPassIsReachableByEveryRoleTest extends TestCase
{
    use RefreshDatabase;

    private const PASS_PATH = '/identity/my-pass';

    public function test_a_role_that_may_see_its_own_pass_is_not_stopped_by_the_route_list(): void
    {
        $nav = $this->frontendFile('services/roleNavigation.js');
        $menu = $this->frontendFile('layouts/AppLayout.vue');

        if ($nav === null || $menu === null) {
            $this->markTestSkipped('Рядом нет каталога frontend: проверка идёт в полном дереве, как в CI.');
        }

        $unlocks = $this->permissionsThatOpenTheOwnPass($menu);
        $this->assertNotSame([], $unlocks, 'В меню не нашёлся пункт «Мой QR-пропуск» — проверка меряла бы пустоту.');

        $this->seed(RoleSeeder::class);

        $closed = [];

        foreach ($this->routePrefixes($nav) as $roleCode => $paths) {
            $role = Role::query()->where('code', $roleCode)->first();

            // Роль, которой в портале нет, ничего не обещает. И роль без права
            // на свой пропуск закрыта правом, а не списком, — это не наш случай.
            if ($role === null || ! $role->permissions()->whereIn('code', $unlocks)->exists()) {
                continue;
            }

            if (! in_array(self::PASS_PATH, $paths, true)) {
                $closed[] = $roleCode.': право на свой пропуск есть, а '.self::PASS_PATH.' в списке путей роли нет';
            }
        }

        $this->assertSame([], $closed, "роль не доходит до своего пропуска:\n".implode("\n", $closed));
    }

    /**
     * Какими правами открывается пункт «Мой QR-пропуск».
     *
     * Читаем у самого пункта, а не помним наизусть: перечень прав у него
     * менялся, и переписанный сюда руками однажды разойдётся с меню молча.
     *
     * @return list<string>
     */
    private function permissionsThatOpenTheOwnPass(string $menu): array
    {
        if (! preg_match("~\{[^{}]*'Мой QR-пропуск'[^{}]*\}~u", $menu, $item)) {
            return [];
        }

        if (! preg_match('~permissionsAny:\s*\[([^\]]*)\]~u', $item[0], $list)) {
            return [];
        }

        preg_match_all("~'([^']+)'~", $list[1], $codes);

        return $codes[1];
    }

    /**
     * Списки путей по ролям из `roleNavigation.js`.
     *
     * @return array<string, list<string>>
     */
    private function routePrefixes(string $nav): array
    {
        if (! preg_match('~const ROLE_ROUTE_PREFIXES = \{(.+?)\n\}~su', $nav, $body)) {
            return [];
        }

        // Комментарии снимаем до разбора: в них перечислены пути, которых в
        // списках нет, и сторож принял бы их за объявленные.
        $text = preg_replace('~^\s*//.*$~mu', '', $body[1]);

        preg_match_all('~(\w+):\s*\[([^\]]*)\]~u', (string) $text, $rows, PREG_SET_ORDER);

        $prefixes = [];

        foreach ($rows as $row) {
            preg_match_all("~'([^']+)'~", $row[2], $paths);
            $prefixes[$row[1]] = $paths[1];
        }

        return $prefixes;
    }

    private function frontendFile(string $path): ?string
    {
        $full = base_path('../frontend/src/'.$path);

        return is_readable($full) ? (string) file_get_contents($full) : null;
    }
}
