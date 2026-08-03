<?php

namespace Tests\Feature;

use App\Models\ApplicantApplication;
use App\Models\EducationProgram;
use App\Models\ImportJob;
use App\Models\Person;
use App\Models\Specialty;
use App\Services\Import\FisAdmissionsImportHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class FisAdmissionsImportHandlerTest extends TestCase
{
    use RefreshDatabase;


    public function test_web_upload_analyze_stores_uploaded_xls_before_processing(): void
    {
        $this->withApiAuth();
        $this->createPrograms();
        $path = $this->fixture('xls', [
            ['1005', 'Принято', '01.07.2026', '01.07.2026', 'Зайцева Зоя Ивановна', '53.02.04 Вокальное искусство', '', '', '', '', 'Россия', 'Женский', '05.05.2008', '', '', '', '', '112-233-445 95', 'z@example.test', '4,90', '1', '5', 'Да', 'Нет'],
        ]);
        $upload = new UploadedFile($path, 'fis-admissions.xls', 'application/vnd.ms-excel', null, true);

        $response = $this->post('/api/admin/import/fis-admissions/analyze', ['file' => $upload], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.source', 'fis_admissions')
            ->assertJsonPath('data.status', 'analyzed')
            ->assertJsonPath('data.metadata.row_count', 1);
        $this->assertNotSame('0', (string) $response->json('data.stored_path'));
        $this->assertDatabaseHas('import_jobs', ['source' => 'fis_admissions', 'status' => 'analyzed']);
    }

    public function test_dry_run_reads_xls_and_masks_personal_data_without_db_changes(): void
    {
        $this->createPrograms();
        $path = $this->fixture('xls', [
            ['1001', 'Принято', '01.07.2026', '01.07.2026', 'Иванов Иван Иванович', '53.02.03 Инструментальное исполнительство', '1234 567890', 'ОВД', '01.01.2020', '123-456', 'Россия', 'Мужской', '01.02.2008', 'Ставрополь', 'Ставропольский край', 'город', 'ул. Учебная, 1', '112-233-445 95', 'ivanov@example.test', '4,75', '2', '101', 'Да', 'Нет'],
        ]);

        $summary = app(FisAdmissionsImportHandler::class)->dryRunPath($path);

        $this->assertSame(1, $summary['total_rows']);
        $this->assertSame(1, $summary['valid_rows']);
        $this->assertSame(1, $summary['new_persons']);
        $this->assertSame(0, $summary['critical_errors']);
        $this->assertDatabaseCount('people', 0);
        $this->assertStringContainsString('***', $summary['preview_rows'][0]['snils']);
        $this->assertSame('[скрыто]', $summary['preview_rows'][0]['address']);
    }

    public function test_apply_endpoint_requires_a_validated_dry_run_job(): void
    {
        $this->withApiAuth();
        $job = ImportJob::create([
            'data_type' => 'applicants',
            'source' => FisAdmissionsImportHandler::SOURCE,
            'mode' => 'analyze',
            'status' => 'analyzed',
            'stored_path' => 'unused',
        ]);

        $this->postJson('/api/admin/import/fis-admissions/apply', ['job_id' => $job->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_id');
    }

    public function test_apply_is_idempotent_and_links_several_applications_to_one_person(): void
    {
        $this->createPrograms();
        $path = $this->fixture('xlsx', [
            ['1001', 'Принято', '01.07.2026', '01.07.2026', 'Иванов Иван Иванович', '53.02.03 Инструментальное исполнительство', '', '', '', '', 'Россия', 'Мужской', '01.02.2008', '', '', '', '', '112-233-445 95', 'ivanov@example.test', '4,75', '2', '101', 'Да', 'Нет'],
            ['1002', 'Принято', '01.07.2026', '01.07.2026', 'Иванов Иван Иванович', '53.02.04 Вокальное искусство', '', '', '', '', 'Россия', 'Мужской', '01.02.2008', '', '', '', '', '112-233-445 95', 'ivanov@example.test', '4,80', '3', '102', 'Да', 'Да'],
        ]);
        $job = ImportJob::create(['data_type' => 'applicants', 'source' => FisAdmissionsImportHandler::SOURCE, 'mode' => 'apply', 'status' => 'uploaded', 'stored_path' => 'unused', 'file_hash' => hash_file('sha256', $path)]);

        $handler = app(FisAdmissionsImportHandler::class);
        $first = $handler->applyPath($path, $job);
        $second = $handler->applyPath($path, $job);

        $this->assertSame(2, $first['created_count']);
        $this->assertSame(0, $first['updated_count']);
        $this->assertSame(0, $second['created_count']);
        $this->assertSame(2, $second['updated_count']);
        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseCount('applicant_applications', 2);
        $this->assertSame(1, ApplicantApplication::query()->distinct('person_id')->count('person_id'));
    }

    public function test_duplicate_application_number_in_one_file_blocks_dry_run(): void
    {
        $this->createPrograms();
        $path = $this->fixture('xlsx', [
            ['1006', 'Принято', '01.07.2026', '01.07.2026', 'Иванов Иван Иванович', '53.02.03 Инструментальное исполнительство', '', '', '', '', 'Россия', 'Мужской', '01.02.2008', '', '', '', '', '112-233-445 95', '', '4,75', '2', '101', 'Да', 'Нет'],
            ['1006', 'Принято', '01.07.2026', '01.07.2026', 'Петров Петр Петрович', '53.02.04 Вокальное искусство', '', '', '', '', 'Россия', 'Мужской', '02.02.2008', '', '', '', '', '112-233-445 95', '', '4,80', '3', '102', 'Да', 'Да'],
        ]);

        $summary = app(FisAdmissionsImportHandler::class)->dryRunPath($path);

        $this->assertSame(1, $summary['critical_errors']);
        $this->assertSame(1, $summary['valid_rows']);
        $this->assertSame(1, $summary['applications']);
        $this->assertSame(0, ApplicantApplication::query()->count());
        $this->assertSame(3, $summary['errors'][0]['row']);
        $this->assertStringContainsString('повторяется в файле', $summary['errors'][0]['reason']);
    }

    public function test_unresolved_competition_blocks_apply(): void
    {
        $this->createPrograms();
        $path = $this->fixture('xls', [
            ['1003', 'Принято', '01.07.2026', '01.07.2026', 'Петров Петр Петрович', 'Неизвестный конкурс', '', '', '', '', 'Россия', 'Мужской', '01.02.2008', '', '', '', '', '112-233-445 95', '', '4,50', '0', '1', 'Да', 'Нет'],
        ]);
        $job = ImportJob::create(['data_type' => 'applicants', 'source' => FisAdmissionsImportHandler::SOURCE, 'mode' => 'apply', 'status' => 'uploaded', 'stored_path' => 'unused']);

        $summary = app(FisAdmissionsImportHandler::class)->dryRunPath($path);
        $this->assertSame(1, $summary['unresolved_competitions']);
        $this->expectException(\RuntimeException::class);
        app(FisAdmissionsImportHandler::class)->applyPath($path, $job);
    }

    public function test_ambiguous_person_duplicate_blocks_apply(): void
    {
        $this->createPrograms();
        Person::create(['last_name' => 'Сидоров', 'first_name' => 'Сидор', 'middle_name' => 'Сидорович', 'birth_date' => '2008-03-04', 'snils' => '112-233-445 95', 'status' => 'active']);
        Person::create(['last_name' => 'Сидоров', 'first_name' => 'Сидор', 'middle_name' => 'Сидорович', 'birth_date' => '2008-03-04', 'snils' => '112-233-445 95', 'status' => 'active']);
        $path = $this->fixture('xls', [
            ['1004', 'Принято', '01.07.2026', '01.07.2026', 'Сидоров Сидор Сидорович', '53.02.03 Инструментальное исполнительство', '', '', '', '', 'Россия', 'Мужской', '04.03.2008', '', '', '', '', '112-233-445 95', '', '4,50', '0', '1', 'Да', 'Нет'],
        ]);
        $job = ImportJob::create(['data_type' => 'applicants', 'source' => FisAdmissionsImportHandler::SOURCE, 'mode' => 'apply', 'status' => 'uploaded', 'stored_path' => 'unused']);

        $summary = app(FisAdmissionsImportHandler::class)->dryRunPath($path);
        $this->assertSame(1, $summary['ambiguous_duplicates']);
        $this->expectException(\RuntimeException::class);
        app(FisAdmissionsImportHandler::class)->applyPath($path, $job);
    }

    public function test_invalid_snils_blocks_apply_before_data_changes(): void
    {
        $this->createPrograms();
        $path = $this->fixture('xls', [
            ['1006', 'Принято', '01.07.2026', '01.07.2026', 'Иванов Иван Иванович', '53.02.03 Инструментальное исполнительство', '', '', '', '', 'Россия', 'Мужской', '01.02.2008', '', '', '', '', '112-233-445 96', '', '4,50', '0', '1', 'Да', 'Нет'],
        ]);
        $job = ImportJob::create(['data_type' => 'applicants', 'source' => FisAdmissionsImportHandler::SOURCE, 'mode' => 'apply', 'status' => 'uploaded', 'stored_path' => 'unused']);

        $this->assertSame(1, app(FisAdmissionsImportHandler::class)->dryRunPath($path)['critical_errors']);
        $this->expectException(\RuntimeException::class);
        app(FisAdmissionsImportHandler::class)->applyPath($path, $job);
    }

    private function createPrograms(): void
    {
        $instrument = Specialty::create(['code' => '53.02.03', 'name' => 'Инструментальное исполнительство']);
        EducationProgram::create(['specialty_id' => $instrument->id, 'name' => 'ППССЗ Инструментальное исполнительство', 'year_start' => 2026, 'study_form' => 'Очная', 'study_years' => 3.8, 'is_active' => true]);
        $vocal = Specialty::create(['code' => '53.02.04', 'name' => 'Вокальное искусство']);
        EducationProgram::create(['specialty_id' => $vocal->id, 'name' => 'ППССЗ Вокальное искусство', 'year_start' => 2026, 'study_form' => 'Очная', 'study_years' => 3.8, 'is_active' => true]);
    }

    private function fixture(string $format, array $rows): string
    {
        $headers = [
            '№ заявления', 'Статус', 'Дата последней проверки', 'Дата регистрации', 'ФИО', 'Конкурс',
            'Документ, удостоверяющий личность', 'Кем выдан документ, удостоверяющий личность',
            'Дата выдачи документа, удостоверяющего личность', 'Код подразделения, выдавшего документ, удостоверяющий личность',
            'Гражданство', 'Пол', 'Дата рождения', 'Место рождения', 'Регион', 'Тип населённого пункта',
            'Адрес', 'СНИЛС', 'E-Mail', 'Средний балл документа об образовании', 'Балл ИД', 'Рейтинг',
            'Документы предоставлены', 'Рекомендован к зачислению',
        ];
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 2));
        }
        $path = tempnam(sys_get_temp_dir(), 'fis-admissions-').'.'.$format;
        $writer = $format === 'xls' ? new Xls($spreadsheet) : new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
        return $path;
    }
}
