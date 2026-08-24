<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Раздел, который роли показан, обязан у неё открываться.
 *
 * Владелец вошёл комендантом и увидел раздел «Нагрузка», а в нём — «У вас нет
 * доступа к этому действию». Искать такое глазами незачем: и меню, и права
 * маршрутов, и наборы прав ролей лежат в репозитории, значит проверяются
 * машиной — по всем ролям и по всем разделам разом.
 *
 * Проверяется три вещи:
 *
 * 1. каждый пункт меню назван в карте запросов ниже. Добавили раздел — впишите
 *    строку, иначе «расхождений нет» будет означать «мы туда не смотрели»;
 * 2. раздел не открывается правом `view_own_data`. Оно есть почти у каждой роли
 *    и значит «показывать человеку его собственное», а не «открыть раздел».
 *    Именно на нём и держалась «Нагрузка»: пункт видели восемь ролей из
 *    тринадцати, включая коменданта и охранника;
 * 3. если пункт роли виден — она проходит проверку **каждого** запроса раздела.
 *    Отказ по вспомогательному запросу закрывает экран целиком.
 *
 * Права маршрутов берутся у самого Laravel, а не разбором `api.php`: групповые
 * middleware и `apiResource` иначе не увидеть.
 */
class MenuMatchesPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Что каждый раздел просит при открытии.
     *
     * Ключ — подпись пункта меню слово в слово. Значение — запросы, без которых
     * экран не покажется; кнопки вроде выгрузки сюда не входят, их нажимают
     * отдельно.
     */
    private const SECTION_REQUESTS = [
        'Люди' => ['people'],
        'Студенты' => ['students'],
        'Группы' => ['groups'],
        'Расписание' => ['schedule-lessons', 'schedule/entries'],
        'Успеваемость' => ['mobile/student'],
        'Журнал' => ['journal/lessons'],
        'Итоговые оценки' => ['semester-grades'],
        'Моя группа' => ['curator/groups'],
        'Посещаемость' => ['attendance/teachers/today', 'attendance/students/today'],
        'Учебные планы' => ['curricula'],
        'Нагрузка' => ['teaching-loads'],
        'Экзамены и ГИА' => ['exams'],
        'Выпускники и дипломы' => ['graduates'],
        'ФРДО' => ['frdo-packages'],
        'ФИС' => ['fis-packages'],
        'Специальности' => ['specialties'],
        'Образовательные программы' => ['education-programs'],
        'Преподаватели' => ['teachers'],
        'Дисциплины' => ['subjects'],
        'Аудитории' => ['classrooms'],
        'Отчеты' => ['reports/attendance-by-group', 'reports/grades-by-group'],
        'Приёмная комиссия' => ['admissions/applications', 'admissions/applicants'],
        'Сотрудники' => ['employees', 'departments', 'positions'],
        'Календарь' => ['hr/calendar'],
        'Подразделения' => ['departments'],
        'Должности' => ['positions'],
        'RFID-карты' => ['rfid-cards'],
        'Проходная' => ['access/events'],
        'Мобильный сканер' => ['access/events'],
        'Кто сейчас в здании' => ['access/muster'],
        'Отчеты по проходам' => ['access/reports/summary', 'access/reports/events'],
        'Корпуса и точки прохода' => ['access/buildings', 'access/points'],
        'Цифровые пропуска' => ['digital-identities'],
        'Пользователи' => ['admin/users', 'admin/users/roles'],
        'Роли' => ['admin/roles'],
        'Разрешения' => ['admin/permissions'],
        'Аудит' => ['admin/audit'],
        'Настройки колледжа' => ['admin/settings'],
        'Справочники' => ['admin/reference/catalogs'],
        'Импорт данных' => ['admin/import/config', 'admin/import/history'],
        'Управление данными' => ['admin/demo-data'],
        'Корзина' => ['trash', 'deletion-requests/pending'],
        'UAT' => ['admin/uat/runs', 'admin/uat/feedback'],
    ];

    /**
     * Разделы, открытые каждому вошедшему: у модели «право есть → 200, права нет
     * → 403» для них нет ожидания.
     */
    private const OPEN_TO_EVERYONE = ['Панель', 'Мой QR-пропуск'];

    /** Служебные экраны без собственных запросов. */
    private const WITHOUT_REQUESTS = ['Тест QR-сканера', 'Библиотека интерфейса'];

    /**
     * Право «видеть своё» — не право на раздел.
     *
     * Оно выдано почти каждой роли и значит ровно одно: человек видит
     * собственные данные там, где они есть. Разделом оно управлять не может —
     * иначе пункт показывается всем подряд, а экран потом отказывает.
     */
    private const NOT_A_SECTION_GATE = ['view_own_data'];

    public function test_every_menu_item_has_its_requests_listed(): void
    {
        $missing = [];

        foreach ($this->menuItems() as $item) {
            if (in_array($item['label'], self::OPEN_TO_EVERYONE, true)
                || in_array($item['label'], self::WITHOUT_REQUESTS, true)
                || $item['adminOnly']) {
                continue;
            }

            if (! array_key_exists($item['label'], self::SECTION_REQUESTS)) {
                $missing[] = $item['label'];
            }
        }

        $this->assertSame([], $missing,
            'Эти разделы меню ничем не проверяются — впишите их запросы в SECTION_REQUESTS: '.implode(', ', $missing));
    }

    public function test_no_section_is_opened_by_the_see_your_own_permission(): void
    {
        $wrong = [];

        foreach ($this->menuItems() as $item) {
            if (in_array($item['label'], self::OPEN_TO_EVERYONE, true)) {
                continue;
            }

            $gates = array_filter(array_merge($item['permissionsAny'], [$item['permission']]));

            foreach (array_intersect($gates, self::NOT_A_SECTION_GATE) as $gate) {
                $wrong[] = "{$item['label']} открывается правом «{$gate}»";
            }
        }

        $this->assertSame([], $wrong,
            "Право «видеть своё» есть почти у всех и разделом управлять не может:\n".implode("\n", $wrong));
    }

    public function test_a_role_that_sees_a_section_can_open_it(): void
    {
        $this->seed(RoleSeeder::class);

        $routes = $this->routePermissions();
        $problems = [];

        foreach (Role::query()->with('permissions')->get() as $role) {
            // Администратор проходит любую проверку через Gate::before, поэтому
            // сравнивать его набор прав бессмысленно.
            if ($role->code === 'admin') {
                continue;
            }

            $granted = $role->permissions->pluck('code')->all();

            foreach ($this->menuItems() as $item) {
                $requests = self::SECTION_REQUESTS[$item['label']] ?? null;

                if ($requests === null || ! $this->itemIsVisible($item, $role->code, $granted)) {
                    continue;
                }

                foreach ($requests as $endpoint) {
                    $required = $routes[$endpoint] ?? null;

                    $this->assertNotNull($required,
                        "Раздел «{$item['label']}» ссылается на запрос {$endpoint}, которого нет среди маршрутов");

                    foreach ($required as $expression) {
                        if (array_intersect(explode(',', $expression), $granted) === []) {
                            $problems[] = "{$role->code}: «{$item['label']}» показан, но {$endpoint} требует «{$expression}»";
                        }
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($problems)),
            "Роль видит раздел, который у неё не откроется:\n".implode("\n", array_unique($problems)));
    }

    /** @return list<array{label: string, permission: ?string, permissionsAny: list<string>, roles: list<string>, adminOnly: bool}> */
    private function menuItems(): array
    {
        $path = base_path('../frontend/src/layouts/AppLayout.vue');

        if (! is_file($path)) {
            // Локальный прогон по инструкции из CLAUDE.md монтирует в контейнер
            // один `backend/`. Тогда проверка честно пропускается, а не
            // притворяется зелёной; в CI дерево целиком, и она работает.
            $this->markTestSkipped('Рядом нет каталога frontend: проверка идёт в полном дереве, как в CI.');
        }

        preg_match_all("/\{\s*label:\s*'([^']+)',\s*to:.*?\}/", (string) file_get_contents($path), $matches, PREG_SET_ORDER);

        $items = [];

        foreach ($matches as $match) {
            [$raw, $label] = $match;

            preg_match("/permission:\s*'([^']+)'/", $raw, $permission);
            preg_match("/permissionsAny:\s*\[([^\]]*)\]/", $raw, $any);
            preg_match("/roles:\s*\[([^\]]*)\]/", $raw, $roles);

            $items[] = [
                'label' => $label,
                'permission' => $permission[1] ?? null,
                'permissionsAny' => $this->codes($any[1] ?? ''),
                'roles' => $this->codes($roles[1] ?? ''),
                'adminOnly' => str_contains($raw, 'adminOnly: true'),
            ];
        }

        $this->assertNotEmpty($items, 'В AppLayout.vue не найдено ни одного пункта меню — разбор сломался');

        return $items;
    }

    /** Права, которые требует каждый GET-маршрут. @return array<string, list<string>> */
    private function routePermissions(): array
    {
        $permissions = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $codes = [];

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                    $codes[] = substr($middleware, strlen('permission:'));
                }
            }

            $permissions[preg_replace('#^api/#', '', $route->uri())] = $codes;
        }

        return $permissions;
    }

    private function itemIsVisible(array $item, string $roleCode, array $granted): bool
    {
        if ($item['adminOnly']) {
            return false;
        }

        if ($item['roles'] !== [] && ! in_array($roleCode, $item['roles'], true)) {
            return false;
        }

        if ($item['permissionsAny'] !== [] && array_intersect($item['permissionsAny'], $granted) === []) {
            return false;
        }

        return $item['permission'] === null || in_array($item['permission'], $granted, true);
    }

    /** @return list<string> */
    private function codes(string $raw): array
    {
        preg_match_all("/'([^']+)'/", $raw, $matches);

        return $matches[1] ?? [];
    }
}
