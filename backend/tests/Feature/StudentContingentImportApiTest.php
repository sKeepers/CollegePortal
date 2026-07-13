<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\ImportJob;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\Student;
use App\Models\StudentStatusHistory;
use App\Services\Import\StudentContingentDocImportHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentContingentImportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyze_uploads_doc_and_creates_private_review_artifacts(): void
    {
        $this->withApiAuth();
        $this->seedReferenceData();
        $upload = UploadedFile::fake()->createWithContent('contingent.doc', $this->fixtureText());

        $response = $this->post('/api/admin/import/student-contingent/analyze', ['file' => $upload], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.source', StudentContingentDocImportHandler::SOURCE)
            ->assertJsonPath('data.status', 'analyzed')
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.metadata.valid_rows', 2)
            ->assertJsonPath('data.metadata.unknown_specialties_count', 0)
            ->assertJsonPath('data.metadata.unknown_groups_count', 0);

        $job = ImportJob::query()->where('source', StudentContingentDocImportHandler::SOURCE)->firstOrFail();
        $this->assertStringStartsWith('imports/students/uploads/', $job->stored_path);
        $this->assertTrue(Storage::disk('local')->exists(data_get($job->metadata, 'artifacts.review_xlsx')));
        $this->assertTrue(Storage::disk('local')->exists(data_get($job->metadata, 'artifacts.normalized_csv')));
        $this->assertTrue(Storage::disk('local')->exists(data_get($job->metadata, 'artifacts.report_json')));
        $this->assertDatabaseCount('students', 0);
    }

    public function test_dry_run_does_not_change_database_and_masks_preview(): void
    {
        $this->withApiAuth();
        $this->seedReferenceData();
        $upload = UploadedFile::fake()->createWithContent('contingent.doc', $this->fixtureText());

        $response = $this->post('/api/admin/import/student-contingent/dry-run', ['file' => $upload], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.result.valid_rows', 2)
            ->assertJsonPath('data.preview_rows.0.student', 'И*** Д*** С***');
        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('students', 0);
    }

    public function test_apply_creates_people_students_and_status_history_idempotently(): void
    {
        $this->withApiAuth();
        $this->seedReferenceData();
        $jobId = $this->dryRunJobId($this->fixtureText());

        $first = $this->postJson('/api/admin/import/student-contingent/apply', ['job_id' => $jobId]);
        $first->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.created_count', 2)
            ->assertJsonPath('data.updated_count', 0);

        $second = $this->postJson('/api/admin/import/student-contingent/apply', ['job_id' => $jobId]);
        $second->assertOk()
            ->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.updated_count', 2);

        $this->assertDatabaseCount('people', 2);
        $this->assertDatabaseCount('students', 2);
        $this->assertGreaterThanOrEqual(2, StudentStatusHistory::query()->count());
    }

    public function test_unknown_specialty_or_group_blocks_apply(): void
    {
        $this->withApiAuth();
        $this->seedReferenceData();
        $fixture = str_replace('Группа: ИСП-101', 'Группа: UNKNOWN-999', $this->fixtureText());
        $jobId = $this->dryRunJobId($fixture, 'validation_failed');

        $response = $this->postJson('/api/admin/import/student-contingent/apply', ['job_id' => $jobId]);

        $response->assertStatus(500);
        $this->assertDatabaseCount('students', 0);
    }

    public function test_ambiguous_person_blocks_apply(): void
    {
        $this->withApiAuth();
        $this->seedReferenceData();
        Person::create(['last_name' => 'Иванов', 'first_name' => 'Дмитрий', 'middle_name' => 'Сергеевич', 'birth_date' => '2008-09-10', 'status' => 'active']);
        Person::create(['last_name' => 'Иванов', 'first_name' => 'Дмитрий', 'middle_name' => 'Сергеевич', 'birth_date' => '2008-09-10', 'status' => 'active']);
        $jobId = $this->dryRunJobId($this->fixtureText(), 'validation_failed');

        $response = $this->postJson('/api/admin/import/student-contingent/apply', ['job_id' => $jobId]);

        $response->assertStatus(500);
        $this->assertDatabaseCount('students', 0);
    }

    public function test_user_without_contingent_permission_cannot_upload(): void
    {
        Role::create(['code' => 'limited', 'name' => 'Limited']);
        Permission::create(['code' => 'import.manage', 'name' => 'Import', 'module' => 'System', 'system' => true, 'active' => true]);
        $user = $this->createApiUser(roleCode: 'limited');
        $this->withApiAuth($user);

        $response = $this->post('/api/admin/import/student-contingent/analyze', [
            'file' => UploadedFile::fake()->createWithContent('contingent.doc', $this->fixtureText()),
        ], ['Accept' => 'application/json']);

        $response->assertForbidden();
    }

    private function dryRunJobId(string $fixture, string $expectedStatus = 'validated'): int
    {
        $response = $this->post('/api/admin/import/student-contingent/dry-run', [
            'file' => UploadedFile::fake()->createWithContent('contingent.doc', $fixture),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('data.status', $expectedStatus);

        return $response->json('data.id');
    }

    private function seedReferenceData(): void
    {
        Specialty::create(['code' => '53.02.03', 'name' => 'Инструментальное исполнительство']);
        Group::create(['name' => 'ИСП-101', 'specialty' => '53.02.03', 'course' => 1, 'year_start' => 2025]);
    }

    private function fixtureText(): string
    {
        return implode("\n", [
            'Контингент студентов 2025-2026',
            'Специальность: 53.02.03 Инструментальное исполнительство',
            'Специализация: Фортепиано',
            '1 курс',
            'Группа: ИСП-101 бюджет',
            '1;1001;Иванов Дмитрий Сергеевич;10.09.2008;приказ 12-к от 01.09.2025;адрес скрыт; +79990000001;',
            '2;1002;Петрова Анна Викторовна;11.10.2008;приказ 12-к от 01.09.2025;адрес скрыт; +79990000002;',
        ]);
    }
}
