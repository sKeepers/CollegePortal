<?php

namespace Tests\Feature;

use App\Models\EducationProgram;
use App\Models\Specialty;
use App\Services\EducationProgramCsvService;
use App\Services\Import\EducationProgramImportHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Выгрузка образовательных программ и обратная загрузка того же файла.
 *
 * Специальность в файле теперь называется кодом, а не идентификатором строки:
 * код есть в документах, идентификатор владелец в Excel не наберёт.
 */
class EducationProgramExportRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_export_columns_match_the_import_template(): void
    {
        $this->seedProgram();

        $headers = array_map(
            fn (string $value): string => trim($value, "\u{FEFF}\" \r"),
            explode(';', $this->exportLines()[0])
        );

        $this->assertSame(app(EducationProgramImportHandler::class)->templateHeaders(), $headers);
    }

    public function test_card_survives_the_round_trip(): void
    {
        $this->seedProgram();
        $csv = implode("\n", $this->exportLines());

        EducationProgram::query()->delete();

        $summary = $this->importCsv($csv);

        $this->assertSame([], $summary['errors']);
        $this->assertSame(1, $summary['created']);

        $program = EducationProgram::query()->firstOrFail();
        $this->assertSame('Фортепиано', $program->name);
        $this->assertSame(2026, (int) $program->year_start);
        $this->assertSame('Очная', $program->study_form);
        $this->assertSame('3.8', (string) $program->study_years);
        $this->assertSame('Программа набора 2026 года', $program->description);
        $this->assertSame(Specialty::query()->value('id'), $program->specialty_id, 'Специальность должна опознаваться по коду');
    }

    /** Признак «Активна» переживает круг: выгружается словом и словом же читается. */
    public function test_inactive_program_stays_inactive_after_the_round_trip(): void
    {
        $this->seedProgram()->update(['is_active' => false]);
        $csv = implode("\n", $this->exportLines());

        EducationProgram::query()->delete();
        $this->importCsv($csv);

        $this->assertFalse((bool) EducationProgram::query()->firstOrFail()->is_active);
    }

    /** Тот же файл, загруженный «Универсальным импортом», даёт тот же результат. */
    public function test_universal_import_resolves_specialty_by_code(): void
    {
        $specialty = $this->seedProgram()->specialty;
        EducationProgram::query()->delete();
        $handler = app(EducationProgramImportHandler::class);

        $row = $handler->prepare([
            'specialty_code' => '53.02.03',
            'name' => 'Фортепиано',
            'year_start' => '2026',
            'study_form' => 'Очная',
            'study_years' => '3.8',
            'is_active' => 'да',
            'description' => null,
        ]);

        $this->assertSame([], $handler->businessValidationErrors($row));
        $this->assertSame('created', $handler->import($row, EducationProgramImportHandler::MODE_CREATE));
        $this->assertSame($specialty->id, EducationProgram::query()->firstOrFail()->specialty_id);
    }

    /** Неизвестный код специальности — ошибка на своей колонке, а не на скрытом поле. */
    public function test_unknown_specialty_code_is_reported_on_its_own_column(): void
    {
        $handler = app(EducationProgramImportHandler::class);
        $row = $handler->prepare([
            'specialty_code' => '99.99.99',
            'name' => 'Фортепиано',
            'year_start' => '2026',
            'study_form' => 'Очная',
        ]);

        $errors = $handler->businessValidationErrors($row);

        $this->assertArrayHasKey('specialty_code', $errors);
        $this->assertStringContainsString('99.99.99', $errors['specialty_code'][0]);
    }

    /** Файлы по прежнему образцу с машинными именами колонок грузятся по-прежнему. */
    public function test_legacy_machine_headers_are_still_accepted(): void
    {
        $this->seedProgram();

        $summary = $this->importCsv("specialty_code;name;year_start;study_form;is_active\n53.02.03;Скрипка;2026;Очная;1\n");

        $this->assertSame([], $summary['errors']);
        $this->assertSame(1, $summary['created']);
        $this->assertTrue(EducationProgram::query()->where('name', 'Скрипка')->exists());
    }

    private function seedProgram(): EducationProgram
    {
        $specialty = Specialty::create([
            'code' => '53.02.03',
            'name' => 'Инструментальное исполнительство',
            'education_level' => 'Среднее профессиональное образование',
        ]);

        return EducationProgram::create([
            'specialty_id' => $specialty->id,
            'name' => 'Фортепиано',
            'year_start' => 2026,
            'study_form' => 'Очная',
            'study_years' => 3.8,
            'is_active' => true,
            'description' => 'Программа набора 2026 года',
        ]);
    }

    /** @return list<string> */
    private function exportLines(): array
    {
        $response = $this->get('/api/education-programs/export');
        $response->assertOk();

        return array_values(array_filter(explode("\n", trim($response->streamedContent()))));
    }

    /** @return array<string, mixed> */
    private function importCsv(string $csv): array
    {
        $path = tempnam(sys_get_temp_dir(), 'programs').'.csv';
        file_put_contents($path, $csv);

        return app(EducationProgramCsvService::class)->import(new UploadedFile($path, 'education-programs.csv', 'text/csv', null, true));
    }
}
