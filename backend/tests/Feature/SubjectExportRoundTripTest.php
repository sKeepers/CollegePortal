<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\Teacher;
use App\Services\Import\SubjectImportHandler;
use App\Services\SubjectCsvService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Выгрузка дисциплин и обратная загрузка того же файла.
 *
 * Выгрузка отдавала привязку преподавателей двумя колонками — `teacher_ids` и
 * `teachers`, — а шаблон импорта такого поля не знал вовсе: файл, загруженный
 * обратно, оставлял дисциплину без преподавателей. Терялось не оформление, а
 * связь: дисциплина без преподавателя не попадает ни в нагрузку, ни в
 * расписание.
 */
class SubjectExportRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_export_columns_match_the_import_template(): void
    {
        $this->seedSubject();

        $headers = array_map(
            fn (string $value): string => trim($value, "\u{FEFF}\" \r"),
            explode(';', $this->exportLines()[0])
        );

        $this->assertSame(app(SubjectImportHandler::class)->templateHeaders(), $headers);
    }

    public function test_teachers_survive_the_round_trip(): void
    {
        $subject = $this->seedSubject();
        $csv = implode("\n", $this->exportLines());

        // Связь снимается целиком: если обратная загрузка её не восстановит,
        // это станет видно, а не спрячется за уже существующей привязкой.
        $subject->teachers()->sync([]);

        $summary = $this->importCsv($csv);

        $this->assertSame([], $summary['errors']);
        $this->assertSame(1, $summary['updated']);
        $this->assertSame(
            ['Петрова Анна Викторовна', 'Смирнова Елена Викторовна'],
            $subject->refresh()->teachers->map(fn (Teacher $teacher): string => trim("{$teacher->last_name} {$teacher->first_name} {$teacher->middle_name}"))->sort()->values()->all()
        );
    }

    /** Тот же файл, загруженный «Универсальным импортом», даёт тот же результат. */
    public function test_universal_import_links_teachers_from_the_template_column(): void
    {
        $this->seedSubject()->teachers()->sync([]);
        $handler = app(SubjectImportHandler::class);

        $row = $handler->prepare([
            'name' => 'Сольфеджио',
            'code' => 'OP.01',
            'department' => 'Теоретическое отделение',
            'description' => null,
            'teachers' => 'Петрова Анна Викторовна | Смирнова Елена Викторовна',
        ]);

        $this->assertSame([], $handler->businessValidationErrors($row));
        $this->assertSame('updated', $handler->import($row, SubjectImportHandler::MODE_UPDATE));
        $this->assertCount(2, Subject::query()->firstOrFail()->teachers);
    }

    /**
     * Файл без колонки преподавателей связь не трогает. Раньше её отсутствие
     * молча отвязывало всех: загрузка реестра, выгруженного до появления
     * колонки, стирала привязку по всей базе и ничего об этом не сообщала.
     */
    public function test_file_without_teacher_column_keeps_existing_links(): void
    {
        $subject = $this->seedSubject();

        $summary = $this->importCsv("Дисциплина;Код;Отделение\nСольфеджио;OP.01;Теоретическое отделение\n");

        $this->assertSame([], $summary['errors']);
        $this->assertCount(2, $subject->refresh()->teachers);
    }

    /** Пустая ячейка — осознанная очистка: человек видит, что стирает. */
    public function test_empty_teacher_cell_detaches_everyone(): void
    {
        $subject = $this->seedSubject();

        $summary = $this->importCsv("Дисциплина;Код;Преподаватели\nСольфеджио;OP.01;\n");

        $this->assertSame([], $summary['errors']);
        $this->assertCount(0, $subject->refresh()->teachers);
    }

    public function test_unknown_teacher_name_is_a_line_error(): void
    {
        $this->seedSubject();

        $summary = $this->importCsv("Дисциплина;Код;Преподаватели\nГармония;OP.03;Несуществующий Преподаватель\n");

        $this->assertSame(0, $summary['created']);
        $this->assertSame(2, $summary['errors'][0]['line']);
        $this->assertStringContainsString('Несуществующий Преподаватель', $summary['errors'][0]['messages'][0]);
        $this->assertDatabaseMissing('subjects', ['code' => 'OP.03']);
    }

    /**
     * Однофамилец не привязывается молча к первому попавшемуся: неверная связь,
     * о которой никто не узнает, хуже отказа строки.
     */
    public function test_ambiguous_teacher_name_is_not_linked_silently(): void
    {
        $this->seedSubject();
        Teacher::create(['last_name' => 'Петрова', 'first_name' => 'Анна', 'middle_name' => 'Сергеевна']);

        $summary = $this->importCsv("Дисциплина;Код;Преподаватели\nГармония;OP.03;Петрова Анна\n");

        $this->assertSame(0, $summary['created']);
        $this->assertStringContainsString('Петрова Анна', $summary['errors'][0]['messages'][0]);
    }

    /** Файлы по прежнему образцу с машинными именами колонок грузятся по-прежнему. */
    public function test_legacy_machine_headers_are_still_accepted(): void
    {
        $subject = $this->seedSubject();
        $teacher = Teacher::query()->where('last_name', 'Смирнова')->firstOrFail();
        $subject->teachers()->sync([]);

        $summary = $this->importCsv("id;name;code;department;teacher_ids\n{$subject->id};Сольфеджио;OP.01;Теоретическое отделение;{$teacher->id}\n");

        $this->assertSame([], $summary['errors']);
        $this->assertSame([$teacher->id], $subject->refresh()->teachers->pluck('id')->all());
    }

    private function seedSubject(): Subject
    {
        $first = Teacher::create(['last_name' => 'Петрова', 'first_name' => 'Анна', 'middle_name' => 'Викторовна']);
        $second = Teacher::create(['last_name' => 'Смирнова', 'first_name' => 'Елена', 'middle_name' => 'Викторовна']);

        $subject = Subject::create([
            'name' => 'Сольфеджио',
            'code' => 'OP.01',
            'department' => 'Теоретическое отделение',
            'description' => 'Базовая дисциплина',
        ]);
        $subject->teachers()->sync([$first->id, $second->id]);

        return $subject;
    }

    /** @return list<string> */
    private function exportLines(): array
    {
        $response = $this->get('/api/subjects/export');
        $response->assertOk();

        return array_values(array_filter(explode("\n", trim($response->streamedContent()))));
    }

    /** @return array<string, mixed> */
    private function importCsv(string $csv): array
    {
        $path = tempnam(sys_get_temp_dir(), 'subjects').'.csv';
        file_put_contents($path, $csv);

        return app(SubjectCsvService::class)->import(new UploadedFile($path, 'subjects.csv', 'text/csv', null, true));
    }
}
