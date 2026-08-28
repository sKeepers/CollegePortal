<?php

namespace Tests\Feature;

use App\Models\Classroom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Пустая клетка в файле кабинетов не роняет строку.
 *
 * Владелец 28.08.2026 назвал кабинеты и **не назвал** ни вместимости, ни типа —
 * их у него нет. Файл по шаблону портала при этом обязан грузиться: пустая клетка
 * значит «нет данных». Загрузчик аудиторий пустое значение не приводил ни к чему,
 * и пустая строка уходила в числовую колонку: первая же проба из десяти строк
 * отказала целиком с `SQLSTATE[22P02] invalid input syntax for type smallint`.
 *
 * Отказ хуже, чем просто отказ: человек видит имя типа PostgreSQL и не понимает,
 * что чинить, — а чинить было нечего, файл был правильный.
 */
class ClassroomEmptyCellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_a_room_without_capacity_and_type_loads(): void
    {
        $result = $this->import(
            "Аудитория;Корпус;Этаж;Вместимость;Тип;Описание\n101;;1;;;\n209;;2;;;Администрация\n",
            ['number' => 'Аудитория', 'building' => 'Корпус', 'floor' => 'Этаж', 'capacity' => 'Вместимость', 'type' => 'Тип', 'description' => 'Описание'],
        );

        $this->assertSame(0, $result['error_count'], 'пустая клетка — это «нет данных», а не ошибка');
        $this->assertSame(2, $result['created_count']);

        $room = Classroom::where('number', '101')->firstOrFail();
        $this->assertNull($room->capacity, 'вместимость осталась незаполненной');
        $this->assertNull($room->type);
        $this->assertSame(1, (int) $room->floor, 'а заполненное — записалось');
    }

    private function import(string $csv, array $mapping): array
    {
        $path = storage_path('framework/testing/classrooms.csv');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $csv);

        $jobId = (int) $this->post('/api/admin/import/preview', [
            'data_type' => 'classrooms',
            'file' => new UploadedFile($path, 'classrooms.csv', 'text/csv', null, true),
        ])->assertCreated()->json('data.id');

        return $this->postJson("/api/admin/import/{$jobId}/confirm", ['mode' => 'create', 'mapping' => $mapping])
            ->assertOk()
            ->json('data');
    }
}
