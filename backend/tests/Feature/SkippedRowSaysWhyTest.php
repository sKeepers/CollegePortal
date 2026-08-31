<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Services\Import\AbstractImportHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Пропущенная строка называет причину, и причины две.
 *
 * `import()` отвечает одним словом «skipped», а приводят к нему **два
 * противоположных** случая: «не нашли по ключу — обновлять нечего» и «нашли —
 * пропускаем как дубликат». До 31.08.2026 оба уходили в счётчик «пропущено N»,
 * и различить их было нечем: «пропущено 3» читалось как «наверное, дубликаты».
 *
 * Проверяется здесь не наличие замечания, а **то, что оно разное**: одинаковое
 * для обоих случаев было бы тем же молчанием, только длиннее.
 */
class SkippedRowSaysWhyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_a_row_not_found_in_update_mode_says_it_was_not_found(): void
    {
        $warnings = $this->import('update', "Аудитория;Корпус\n101;Голенева, 21\n", skipped: 1);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('не найдена по ключевым полям', $warnings[0]['reason']);
        // Номер строки обязателен: без него в файле на триста строк причина
        // ничего не стоит.
        $this->assertSame(2, $warnings[0]['row']);
    }

    public function test_a_duplicate_says_it_is_a_duplicate_not_that_it_was_not_found(): void
    {
        Classroom::create(['number' => '101', 'building' => 'Голенева, 21']);

        $warnings = $this->import('skip_duplicates', "Аудитория;Корпус\n101;Голенева, 21\n", skipped: 1);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('уже есть', $warnings[0]['reason']);
        // И главное: два случая не должны звучать одинаково.
        $this->assertStringNotContainsString('не найдена', $warnings[0]['reason']);
    }

    public function test_a_row_that_loads_leaves_no_skip_notice(): void
    {
        // Обратная сторона: замечание не должно появляться там, где ничего не
        // пропущено. Иначе «замечаний 300» перестанет значить что-либо.
        $warnings = $this->import('create', "Аудитория;Корпус\n101;Голенева, 21\n", skipped: 0, created: 1);

        $this->assertSame([], $warnings);
    }

    public function test_only_the_skipped_row_gets_a_notice(): void
    {
        // Замечание идёт на пропущенную строку и **только** на неё: рядом
        // загружается вторая, и она в замечания попасть не должна.
        //
        // Первая редакция этой проверки называлась «причина не течёт со строки
        // на строку» и **не проверяла этого**: выключение `forgetSkipReason()`
        // оставляло её зелёной. Течь причине сейчас некуда — все пятнадцать
        // мест пропуска называют повод, — и проверять надо то, что проверяешь.
        Classroom::create(['number' => '101', 'building' => 'Голенева, 21']);

        $warnings = $this->import(
            'skip_duplicates',
            "Аудитория;Корпус\n101;Голенева, 21\n202;Серова, 277\n",
            skipped: 1,
            created: 1,
        );

        $this->assertCount(1, $warnings, 'замечание должно быть одно — на пропущенную строку, а не на обе');
        $this->assertSame(2, $warnings[0]['row']);
    }

    public function test_the_handler_forgets_the_reason_when_asked(): void
    {
        // Забывание проверяется на самом обработчике, потому что через службу
        // его не создать: пятнадцать загрузчиков прописаны в её конструкторе,
        // подсунуть шестнадцатый некуда. А проверить надо: `forgetSkipReason()`
        // — единственное, что удержит причину от протекания, когда появится
        // загрузчик, возвращающий пропуск **без** повода.
        $handler = new class extends AbstractImportHandler {
            public function type(): string { return 'проба'; }
            public function label(): string { return 'Проба'; }
            public function modelClass(): string { return Classroom::class; }
            public function keyFields(): array { return ['number']; }
            public function fields(): array { return ['number' => ['label' => 'Номер', 'required' => true, 'aliases' => []]]; }
            public function templateHeaders(): array { return ['Номер']; }
            public function templateExample(): array { return ['101']; }
            public function rules(): array { return ['number' => ['required']]; }
            public function findExisting(array $data): ?Model { return null; }

            /** Пропуск без повода — то, чего сегодня нет ни в одном загрузчике. */
            public function import(array $data, string $mode): string { return 'skipped'; }

            public function rememberSomething(): void { $this->skipped(self::SKIP_DUPLICATE); }
        };

        $handler->rememberSomething();
        $this->assertNotNull($handler->lastSkipReason());

        $handler->forgetSkipReason();
        $this->assertNull($handler->lastSkipReason(), 'после забывания повод обязан исчезнуть');

        // И тогда служба напишет своё: пропуск есть, а объяснения нет.
        $this->assertSame('skipped', $handler->import([], 'update'));
        $this->assertNull($handler->lastSkipReason());
    }

    /** @return array<int, array<string, mixed>> замечания задания */
    private function import(string $mode, string $csv, int $skipped, int $created = 0): array
    {
        $file = UploadedFile::fake()->createWithContent('classrooms.csv', "\xEF\xBB\xBF".$csv);

        $jobId = $this->post('/api/admin/import/preview', ['data_type' => 'classrooms', 'file' => $file])
            ->assertCreated()
            ->json('data.id');

        return $this->postJson("/api/admin/import/{$jobId}/confirm", [
            'mode' => $mode,
            'mapping' => ['number' => 'Аудитория', 'building' => 'Корпус'],
        ])
            ->assertOk()
            ->assertJsonPath('data.skipped_count', $skipped)
            ->assertJsonPath('data.created_count', $created)
            ->json('data.warnings');
    }
}
