<?php

namespace Tests\Feature;

use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Дисциплина без кода находится по названию — и повторная загрузка не двоит.
 *
 * Ключ у дисциплин объявлен (`code`), а при пустом коде `findExisting` ищет по
 * названию. Читая эти две строки, легко решить, что защита есть. Её не было:
 * `prepare()` проставлял автокод **до** поиска, и поиск шёл по коду, которого в
 * портале ещё нет ни у кого. Ветка с поиском по названию не выполнялась ни разу.
 *
 * Стоило это одиннадцати задвоенных дисциплин в рабочей базе стенда 28.08.2026:
 * проба в десять строк, потом весь файл в режиме «пропускать дубли» — и «создано
 * 140, пропущено 0». Отказавший ключ хуже отсутствующего: режим обещает защиту.
 */
class SubjectKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_the_same_file_loaded_twice_does_not_double_the_subjects(): void
    {
        $csv = "Дисциплина;Код\nСольфеджио;\nГармония;\n";
        $mapping = ['name' => 'Дисциплина', 'code' => 'Код'];

        $first = $this->import('subjects', $csv, $mapping, 'create');
        $this->assertSame(2, $first['created_count']);

        $second = $this->import('subjects', $csv, $mapping, 'skip_duplicates');
        $this->assertSame(0, $second['created_count'], 'вторая загрузка не заводит заново');
        $this->assertSame(2, $second['skipped_count'], 'она пропускает уже загруженное');

        $this->assertSame(2, Subject::count());
    }

    public function test_the_code_given_once_is_not_replaced_by_a_new_one(): void
    {
        $this->import('subjects', "Дисциплина;Код\nСольфеджио;\n", ['name' => 'Дисциплина', 'code' => 'Код'], 'create');

        $was = Subject::firstOrFail()->code;
        $this->assertNotNull($was, 'новой дисциплине код всё-таки выдаётся');

        $this->import('subjects', "Дисциплина;Код\nСольфеджио;\n", ['name' => 'Дисциплина', 'code' => 'Код'], 'update');

        $this->assertSame($was, Subject::firstOrFail()->code, 'повторная загрузка не переписывает код');
        $this->assertSame(1, Subject::count());
    }

    private function import(string $type, string $csv, array $mapping, string $mode): array
    {
        $path = storage_path('framework/testing/subject-key.csv');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $csv);

        $jobId = (int) $this->post('/api/admin/import/preview', [
            'data_type' => $type,
            'file' => new UploadedFile($path, 'subject-key.csv', 'text/csv', null, true),
        ])->assertCreated()->json('data.id');

        return $this->postJson("/api/admin/import/{$jobId}/confirm", ['mode' => $mode, 'mapping' => $mapping])
            ->assertOk()
            ->json('data');
    }
}
