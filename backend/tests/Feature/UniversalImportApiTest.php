<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\ImportJob;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UniversalImportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_returns_import_config(): void
    {
        $this->getJson('/api/admin/import/config')
            ->assertOk()
            ->assertJsonPath('data.types.0.value', 'students')
            ->assertJsonPath('data.formats.1', 'xlsx');
    }


    public function test_it_downloads_csv_template_with_russian_headers(): void
    {
        $response = $this->get('/api/admin/import/templates/students.csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->getContent();
        $this->assertStringContainsString('Фамилия;Имя;Отчество;Группа', $content);
        $this->assertStringContainsString('Иванов;Дмитрий;Сергеевич;ИСП-101', $content);
    }

    public function test_it_previews_and_confirms_subject_import(): void
    {
        $file = $this->csvFile('subjects.csv', "name;code;department
История музыки;MUS-777;Музыкальное отделение
");

        $jobId = $this->post('/api/admin/import/preview', [
            'data_type' => 'subjects',
            'file' => $file,
        ])
            ->assertCreated()
            ->assertJsonPath('data.total_rows', 1)
            ->assertJsonPath('data.mapping.name', 'name')
            ->json('data.id');

        $this->postJson("/api/admin/import/{$jobId}/confirm", [
            'mode' => 'skip_duplicates',
            'mapping' => ['name' => 'name', 'code' => 'code', 'department' => 'department', 'description' => null],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.error_count', 0);

        $this->assertDatabaseHas('subjects', ['code' => 'MUS-777', 'name' => 'История музыки']);
    }

    public function test_it_validates_student_import_errors_before_confirm(): void
    {
        $file = $this->csvFile('students.csv', "last_name;first_name;group
Иванов;Дмитрий;НЕСУЩ
");

        $jobId = $this->post('/api/admin/import/preview', [
            'data_type' => 'students',
            'file' => $file,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/import/{$jobId}/validate", [
            'mode' => 'skip_duplicates',
            'mapping' => ['last_name' => 'last_name', 'first_name' => 'first_name', 'group_name' => 'group'],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'validation_failed')
            ->assertJsonPath('data.error_count', 1)
            ->assertJsonPath('data.validation_errors.0.row', 2)
            ->assertJsonPath('data.validation_errors.0.column', 'ID группы')
            ->assertJsonPath('data.validation_errors.0.value', null);
    }

    public function test_it_updates_existing_group_by_key(): void
    {
        Group::create(['name' => 'ИСП-101', 'specialty' => 'Старая специальность', 'course' => 1, 'year_start' => 2026]);
        $file = $this->csvFile('groups.csv', "name;specialty;course;year_start
ИСП-101;Инструментальное исполнительство;2;2026
");

        $jobId = $this->post('/api/admin/import/preview', [
            'data_type' => 'groups',
            'file' => $file,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/import/{$jobId}/confirm", [
            'mode' => 'update',
            'mapping' => ['name' => 'name', 'specialty' => 'specialty', 'course' => 'course', 'year_start' => 'year_start'],
        ])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1);

        $this->assertDatabaseHas('groups', ['name' => 'ИСП-101', 'course' => 2, 'specialty' => 'Инструментальное исполнительство']);
    }

    public function test_it_records_import_history(): void
    {
        ImportJob::create([
            'data_type' => 'subjects',
            'mode' => 'create',
            'status' => 'completed',
            'original_filename' => 'subjects.csv',
            'total_rows' => 2,
            'created_count' => 2,
        ]);

        $this->getJson('/api/admin/import/history?data_type=subjects')
            ->assertOk()
            ->assertJsonPath('data.0.original_filename', 'subjects.csv')
            ->assertJsonPath('data.0.created_count', 2);
    }

    private function csvFile(string $name, string $content): UploadedFile
    {
        $path = storage_path('framework/testing/'.$name);
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
