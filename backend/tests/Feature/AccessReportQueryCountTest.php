<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Отчёт проходной: число запросов не зависит от числа событий на странице.
 *
 * Та же беда, что и в списке эвакуации (находка 13 аудита от 08.08.2026), и в
 * том же месте: `AccessEvent::owner` — не связь, а аксессор, и обращение к нему
 * в цикле ходит в базу на каждую строку. Список эвакуации тогда починили, отчёт
 * пропустили. Замер на стенде 16.08.2026: одна страница отчёта — **1810
 * запросов**.
 *
 * Тест считает запросы, а не время: время на стенде и в CI разное, а
 * зависимость числа запросов от числа строк — то самое свойство, которое
 * сломалось дважды.
 */
class AccessReportQueryCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_the_screen_does_not_query_per_event(): void
    {
        $this->eventsFor(3, 'Иванов');
        $few = $this->countQueries();

        $this->eventsFor(60, 'Петров');
        $many = $this->countQueries();

        // Тридцатикратный рост числа строк не должен давать роста числа
        // запросов. До правки было около полутора запросов на строку.
        $this->assertSame(
            $few,
            $many,
            "Запросов стало {$many} вместо {$few}: отчёт снова спрашивает владельца на каждую строку"
        );
    }

    /**
     * Проверка, что считаем не пустоту: страница действительно наполняется, и
     * владелец у строки есть — иначе одинаковое число запросов означало бы
     * «оба раза ничего не выбрали».
     */
    public function test_the_screen_still_names_the_owner(): void
    {
        $this->eventsFor(3, 'Иванов');

        $this->getJson('/api/access/reports/events')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.owner.last_name', 'Иванов');
    }

    /**
     * Выгрузка идёт курсором и владельцев разбирает пачками — на каждую строку
     * она ходить в базу тоже не должна.
     */
    public function test_the_export_does_not_query_per_event(): void
    {
        $this->eventsFor(40, 'Иванов');

        // Прогрев: первый запрос тянет за собой права и настройки, и без него
        // счёт был бы про них, а не про отчёт.
        $this->get('/api/access/reports/events?export=csv')->assertOk()->streamedContent();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get('/api/access/reports/events?export=csv');
        $content = $response->streamedContent();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $lines = array_filter(explode("\n", trim($content)));

        $this->assertCount(41, $lines, 'Сорок строк и заголовок');
        $this->assertStringContainsString('Иванов', $content, 'Владелец обязан доехать и до выгрузки');
        $this->assertLessThan(25, $queries, "Выгрузка сорока строк стоила {$queries} запросов");
    }

    private function countQueries(): int
    {
        // Первый запрос считать нельзя: в нём разрешение прав и настройки,
        // которых во втором уже не будет.
        $this->getJson('/api/access/reports/events')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/access/reports/events')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function eventsFor(int $count, string $lastName): void
    {
        $group = Group::query()->firstOrCreate(
            ['name' => 'ИСП-101'],
            ['specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026],
        );

        $now = now();
        $rows = [];

        foreach (range(1, $count) as $index) {
            $student = Student::create([
                'group_id' => $group->id,
                'last_name' => $lastName,
                'first_name' => 'Имя'.$index,
                'status' => 'active',
            ]);

            $identity = DigitalIdentity::create([
                'entity_type' => 'student',
                'entity_id' => $student->id,
                'token' => (string) Str::uuid(),
                'status' => 'active',
                'issued_at' => $now,
            ]);

            $rows[] = [
                'digital_identity_id' => $identity->id,
                'entity_type' => 'student',
                'entity_id' => $student->id,
                'direction' => AccessEvent::DIRECTION_IN,
                'event_time' => $now->copy()->subMinutes($index),
                'result' => AccessEvent::RESULT_ALLOWED,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        AccessEvent::query()->insert($rows);
    }
}
