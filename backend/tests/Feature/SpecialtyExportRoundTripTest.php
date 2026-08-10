<?php

namespace Tests\Feature;

use App\Models\Specialty;
use App\Services\Import\SpecialtyImportHandler;
use App\Services\SpecialtyCsvService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Выгрузка специальностей и обратная загрузка того же файла.
 *
 * Обработчика универсального импорта у специальностей не было вовсе, а
 * выгрузка отдавала машинные имена полей — последние два реестра, где так
 * оставалось, это специальности и образовательные программы.
 */
class SpecialtyExportRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_export_columns_match_the_import_template(): void
    {
        $this->seedSpecialty();

        $headers = array_map(
            fn (string $value): string => trim($value, "\u{FEFF}\" \r"),
            explode(';', $this->exportLines()[0])
        );

        $this->assertSame(app(SpecialtyImportHandler::class)->templateHeaders(), $headers);
    }

    public function test_card_survives_the_round_trip(): void
    {
        $this->seedSpecialty();
        $csv = implode("\n", $this->exportLines());

        // Реестр стирается целиком: потеря станет видна, а не спрячется за
        // уже существующей строкой.
        Specialty::query()->delete();

        $summary = $this->importCsv($csv);

        $this->assertSame([], $summary['errors']);
        $this->assertSame(1, $summary['created']);

        $specialty = Specialty::query()->firstOrFail();
        $this->assertSame('53.02.03', $specialty->code);
        $this->assertSame('Инструментальное исполнительство', $specialty->name);
        $this->assertSame('Артист, преподаватель, концертмейстер', $specialty->qualification);
        $this->assertSame('3.8', (string) $specialty->normative_study_years);
        $this->assertSame('Профильная специальность', $specialty->description);
    }

    /** Тот же файл, загруженный «Универсальным импортом», даёт тот же результат. */
    public function test_universal_import_accepts_the_template(): void
    {
        $handler = app(SpecialtyImportHandler::class);
        $row = $handler->prepare([
            'code' => '53.02.03',
            'name' => 'Инструментальное исполнительство',
            'education_level' => 'Среднее профессиональное образование',
            'qualification' => 'Артист, преподаватель, концертмейстер',
            'normative_study_years' => '3.8',
            'description' => null,
        ]);

        $this->assertSame('created', $handler->import($row, SpecialtyImportHandler::MODE_CREATE));
        $this->assertSame('Инструментальное исполнительство', Specialty::query()->firstOrFail()->name);
    }

    /** Файлы по прежнему образцу с машинными именами колонок грузятся по-прежнему. */
    public function test_legacy_machine_headers_are_still_accepted(): void
    {
        $summary = $this->importCsv("code;name;education_level;qualification\n54.02.01;Дизайн;Среднее профессиональное образование;Дизайнер\n");

        $this->assertSame([], $summary['errors']);
        $this->assertSame(1, $summary['created']);
        $this->assertSame('Дизайн', Specialty::query()->where('code', '54.02.01')->value('name'));
    }

    private function seedSpecialty(): Specialty
    {
        return Specialty::create([
            'code' => '53.02.03',
            'name' => 'Инструментальное исполнительство',
            'education_level' => 'Среднее профессиональное образование',
            'qualification' => 'Артист, преподаватель, концертмейстер',
            'normative_study_years' => 3.8,
            'description' => 'Профильная специальность',
        ]);
    }

    /** @return list<string> */
    private function exportLines(): array
    {
        $response = $this->get('/api/specialties/export');
        $response->assertOk();

        return array_values(array_filter(explode("\n", trim($response->streamedContent()))));
    }

    /** @return array<string, mixed> */
    private function importCsv(string $csv): array
    {
        $path = tempnam(sys_get_temp_dir(), 'specialties').'.csv';
        file_put_contents($path, $csv);

        return app(SpecialtyCsvService::class)->import(new UploadedFile($path, 'specialties.csv', 'text/csv', null, true));
    }
}
