<?php

namespace Tests\Feature;

use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Список преподавателей не обрывается на двадцатом.
 *
 * `TeacherController::index` звал `paginate(20)` жёстким числом и `per_page` не
 * спрашивал вовсе. Пока преподавателей было четверо, это ничего не значило.
 * 28.08.2026 их стало 177 — и первыми двадцатью по алфавиту оборвались разом
 * все справочники: выбор преподавателя в расписании, в нагрузке, у дисциплины,
 * у группы. Владелец как раз собирался с этого экрана назначать, кто какой
 * предмет ведёт, и после буквы «Б» не нашёл бы никого.
 *
 * Хуже справочников был кабинет: `TeacherDashboard` ищет вошедшего среди
 * присланных строк, и преподаватель за двадцатым себя не находил — свой журнал
 * и свою нагрузку он видел пустыми.
 *
 * Здесь два сторожа. Первый — что ответ слушается `per_page`. Второй читает
 * сами хранилища фронтенда: любой новый вызов списка преподавателей без
 * `per_page` вернёт ту же беду молча, а список таких вызовов, переписанный в
 * тест руками, однажды разойдётся с кодом.
 */
class TeachersListIsWholeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_the_list_gives_more_than_twenty_when_asked(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            Teacher::create([
                'last_name' => sprintf('Преподавателев%02d', $i),
                'first_name' => 'Имя',
                'middle_name' => 'Отчество',
                'is_active' => true,
            ]);
        }

        $this->getJson('/api/teachers?per_page=100')->assertOk()->assertJsonCount(25, 'data');
        $this->getJson('/api/teachers')->assertOk()->assertJsonCount(20, 'data');
        $this->getJson('/api/teachers?per_page=5')->assertOk()->assertJsonCount(5, 'data');
    }

    /**
     * Ни одно хранилище не просит список преподавателей, не назвав `per_page`.
     *
     * Проверка читает каталог целиком, а не перечисляет файлы: хранилищ станет
     * больше, и молчаливый обрыв вернётся именно в новом. Поиск по строке
     * (`searchService`) сюда не относится — там двадцать найденных это ответ, а
     * не обрезанный справочник, и он спрашивает с `search`.
     */
    public function test_no_store_asks_for_teachers_without_a_page_size(): void
    {
        $directory = base_path('../frontend/src/stores');

        if (! is_dir($directory)) {
            $this->markTestSkipped('Рядом нет каталога frontend: проверка идёт в полном дереве, как в CI.');
        }

        $offenders = [];
        $seen = 0;

        foreach (glob($directory.'/*.js') as $file) {
            foreach (file($file) as $number => $line) {
                if (! str_contains($line, "api.list('teachers'")) {
                    continue;
                }

                $seen++;

                if (! str_contains($line, 'per_page')) {
                    $offenders[] = basename($file).':'.($number + 1);
                }
            }
        }

        $this->assertGreaterThan(0, $seen, 'Ни одного вызова списка преподавателей не найдено — разбор сломался');
        $this->assertSame([], $offenders, 'Список преподавателей просят без per_page: '.implode(', ', $offenders));
    }
}
