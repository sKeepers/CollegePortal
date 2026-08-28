<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Person;
use App\Models\Student;
use App\Support\Http\PageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Список отдаёт столько строк, сколько у него просят.
 *
 * До 28.08.2026 не отдавал: `per_page` был декорацией у девятнадцати списков —
 * число стояло в коде, запрос не спрашивали. Замер на стенде:
 * `students?per_page=1000` возвращал 20 строк из 596, `people` — 30 из 841.
 * Экран «Студенты» при этом писал «Найдено записей: 596» и показывал двадцать.
 *
 * Проверок три, и они разные:
 *
 * 1. **Сторож обходит каталог контроллеров сам** и падает на любом `paginate(`
 *    с числом-константой. Списка файлов у него нет намеренно: список однажды
 *    разойдётся с каталогом и промолчит — новый контроллер появится мимо него.
 * 2. **Правило вправду работает** — просим одну строку и получаем одну, просим
 *    больше потолка и получаем потолок. Сторож из первого пункта читает
 *    исходник; этот читает поведение, и без него первый доказывал бы только
 *    отсутствие знакомой записи.
 * 3. **Умолчание не сдвинулось.** Правка меняет поведение лишь для того, кто
 *    спросил: вызов без `per_page` обязан отдавать ровно столько же, сколько
 *    отдавал вчера. Иначе девятнадцать экранов меняются молча.
 */
class ListsHonourPageSizeTest extends TestCase
{
    use RefreshDatabase;

    /** Умолчание студентов до правки и после неё. */
    private const STUDENTS_DEFAULT = 20;

    public function test_no_list_paginates_by_a_number_written_into_the_code(): void
    {
        $directory = app_path('Http/Controllers/Api');
        $files = glob($directory.'/*.php');

        $this->assertNotEmpty($files, 'Каталог контроллеров пуст — сторож смотрит не туда.');

        $guilty = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match_all('/paginate\(\s*\d+\s*\)/', $source, $matches) === 0) {
                continue;
            }

            $guilty[] = basename($file).': '.implode(', ', $matches[0]);
        }

        $this->assertSame([], $guilty, implode("\n", array_merge(
            ['Эти списки страничат числом из кода и не слушают `per_page`.'],
            ['Замените число на `PageSize::from($request, <прежнее умолчание>)` — умолчание оставьте тем же.'],
            $guilty,
        )));
    }

    /**
     * Ни один список, к которому ходит помощник, не отказывает ему в странице.
     *
     * 29.08.2026 владелец увидел на экране «RFID-карты» красную полосу «Поле
     * „per page“ должно быть не больше 200» — и пустой реестр при 244 картах.
     * Причина: `api.listAll` просит одну крупную страницу для всех, а ручка с
     * потолком ниже **не отдаёт своё, а отвечает 422**. Так же погасли три
     * экрана общежития с потолком 300.
     *
     * Поймать это можно было только запросом: и код, и прогон по отдельности
     * выглядели правильными. Поэтому сторож читает **обе стороны** и ничего не
     * помнит наизусть:
     *
     * - какие ресурсы зовут через `listAll` и сколько строк просят — из самого
     *   фронтенда;
     * - какой контроллер отвечает за ресурс — из таблицы маршрутов;
     * - какой потолок он объявляет — из его исходника.
     *
     * Список ресурсов руками сюда вписывать нельзя: ровно из-за такого списка
     * `rfid-cards` и не попал в первый обход — его там не было, а в портале он
     * был.
     */
    public function test_no_list_reached_by_the_helper_refuses_the_page_it_asks_for(): void
    {
        $api = base_path('../frontend/src/services/api.js');

        if (! is_readable($api)) {
            // Дерево смонтировано без фронтенда — читать нечего. Проверка выше,
            // про числа в коде, выполняется и здесь.
            $this->addToAssertionCount(1);

            return;
        }

        preg_match('/const WHOLE_LIST_PAGE = (\d+)/', (string) file_get_contents($api), $match);
        $asked = (int) ($match[1] ?? 0);

        $this->assertGreaterThan(0, $asked, 'В `api.js` не нашлось `WHOLE_LIST_PAGE`: неизвестно, сколько просит помощник.');
        $this->assertLessThanOrEqual(PageSize::MAX, $asked, 'Помощник просит больше, чем разрешает `PageSize::MAX`.');

        $resources = [];

        foreach ($this->frontendSources() as $file) {
            if (preg_match_all("/listAll\(\s*'([a-z0-9\/_-]+)'/i", (string) file_get_contents($file), $found)) {
                $resources = array_merge($resources, $found[1]);
            }
        }

        $resources = array_values(array_unique($resources));
        $this->assertNotEmpty($resources, 'Ни одного вызова `listAll` не нашлось — сторож смотрит не туда.');

        $guilty = [];

        foreach ($resources as $resource) {
            $controller = $this->controllerFor($resource);

            if ($controller === null || ! is_readable($controller)) {
                continue;
            }

            $source = (string) file_get_contents($controller);

            if (preg_match_all("/'per_page'\s*=>\s*\[[^\]]*max:(\d+)/", $source, $ceilings) === 0) {
                continue;
            }

            foreach ($ceilings[1] as $ceiling) {
                if ((int) $ceiling < $asked) {
                    $guilty[] = sprintf('%s → %s: потолок %d, а просят %d', $resource, basename($controller), $ceiling, $asked);
                }
            }
        }

        $this->assertSame([], $guilty, implode("\n", array_merge(
            ['Эти списки ответят помощнику отказом 422, и экран не наполнится вовсе:'],
            $guilty,
            ['Поднимите потолок до `PageSize::MAX` — или перестаньте звать туда `listAll`, если низкий потолок осмыслен.'],
        )));
    }

    /**
     * Никто не передаёт помощнику размер страницы.
     *
     * `listAll` берёт список целиком и переданный размер игнорирует — значит
     * параметр в вызове читается как действующий, а не действует. Так четыре
     * подсказки по мере набора превратились из «первых тридцати» в «всех
     * подходящих»: замер на стенде 29.08.2026 — по запросу «а» подходит 826
     * человек из 841, по «ан» — 402, и это на каждое нажатие клавиши.
     *
     * Подсказке нужен `list` с явным пределом: там тридцать строк — не
     * обрезанный список, а осознанная граница.
     */
    public function test_nobody_hands_the_helper_a_page_size(): void
    {
        $sources = $this->frontendSources();

        if ($sources === []) {
            $this->addToAssertionCount(1);

            return;
        }

        $guilty = [];

        foreach ($sources as $file) {
            // Комментарии выбрасываются до разбора: в самом помощнике вызов с
            // `per_page` приведён как пример того, чего делать нельзя, и сторож
            // краснел на нём. Проверка, кричащая на невиновных, живёт до первой
            // спешки, а потом её выключают.
            $source = $this->withoutComments((string) file_get_contents($file));

            if (preg_match_all("/listAll\([^)]*\b(per_page|page)\s*:/", $source, $found) === 0) {
                continue;
            }

            foreach ($found[1] as $parameter) {
                $guilty[] = basename($file).': listAll(..., { '.$parameter.': ... })';
            }
        }

        $this->assertSame([], $guilty, implode("\n", array_merge(
            ['Помощнику передают размер страницы, а он его не принимает — параметр вводит в заблуждение:'],
            $guilty,
            ['Нужен предел — зовите `list`. Нужен весь список — уберите параметр.'],
        )));
    }

    /** Исходник без комментариев: строчных и блочных. */
    private function withoutComments(string $source): string
    {
        $source = preg_replace('#/\\*.*?\\*/#s', '', $source) ?? $source;

        return preg_replace('#^\\s*//.*$#m', '', $source) ?? $source;
    }

    /** @return list<string> */
    private function frontendSources(): array
    {
        $root = base_path('../frontend/src');

        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $walker = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($walker as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['js', 'vue'], true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** Какой контроллер отвечает за `GET /api/<resource>`. */
    private function controllerFor(string $resource): ?string
    {
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if ($route->uri() !== 'api/'.$resource || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $action = $route->getAction('controller');

            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }

            [$class] = explode('@', $action);

            if (! class_exists($class)) {
                continue;
            }

            return (new \ReflectionClass($class))->getFileName() ?: null;
        }

        return null;
    }

    public function test_a_list_gives_as_many_rows_as_asked(): void
    {
        $this->withApiAuth();
        $this->makeStudents(3);

        $this->getJson('/api/students?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/students?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_request_above_the_ceiling_gets_the_ceiling_and_not_the_old_number(): void
    {
        // Потолок — утверждение об одном ответе, а не о колледже. Просивший
        // больше обязан получить потолок: молча вернуть прежние двадцать значит
        // соврать тому, кто как раз проверял.
        $this->withApiAuth();
        $this->makeStudents(2);

        $this->getJson('/api/students?per_page='.(PageSize::MAX + 1000))
            ->assertOk()
            ->assertJsonPath('meta.per_page', PageSize::MAX);
    }

    public function test_the_default_stays_where_it_was(): void
    {
        $this->withApiAuth();
        $this->makeStudents(2);

        $this->getJson('/api/students')
            ->assertOk()
            ->assertJsonPath('meta.per_page', self::STUDENTS_DEFAULT);
    }

    private function makeStudents(int $count): void
    {
        $group = Group::firstOrCreate(
            ['name' => 'Теория музыки, набор 2024'],
            ['specialty' => 'Теория музыки', 'year_start' => 2024],
        );

        for ($i = 0; $i < $count; $i++) {
            $person = Person::create([
                'uuid' => (string) Str::uuid(),
                'last_name' => 'Страничный',
                'first_name' => 'Вымышленный',
                'middle_name' => 'Номер'.$i,
                'birth_date' => '2006-01-0'.($i + 1),
                'status' => 'active',
            ]);

            Student::create([
                'person_id' => $person->id,
                'group_id' => $group->id,
                'last_name' => 'Страничный',
                'first_name' => 'Вымышленный',
                'middle_name' => 'Номер'.$i,
                'birth_date' => '2006-01-0'.($i + 1),
                'status' => 'active',
            ]);
        }
    }
}
