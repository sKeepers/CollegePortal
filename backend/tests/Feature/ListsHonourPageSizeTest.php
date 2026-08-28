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
