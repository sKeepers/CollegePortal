<?php

namespace Tests\Feature;

use App\Models\ApplicantApplication;
use App\Models\ApplicantApplicationDocument;
use App\Models\ApplicantDocumentFile;
use App\Models\EducationProgram;
use App\Models\ReferenceCatalog;
use App\Models\Specialty;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicantDocumentRegistryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    public function test_it_creates_required_registry_and_calculates_completeness(): void
    {
        $this->withApiAuth($this->createApiUser(roleCode: 'admission'));
        $application = $this->makeApplication();

        $this->getJson("/api/admissions/{$application->id}/documents")
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('meta.documents_status', 'no_documents')
            ->assertJsonPath('meta.documents_count', 0)
            ->assertJsonPath('meta.required_documents_count', 6);

        $this->postJson("/api/admissions/{$application->id}/documents/passport/receive")
            ->assertOk()
            ->assertJsonPath('data.status', 'received');

        $this->getJson("/api/admissions/{$application->id}/documents")
            ->assertOk()
            ->assertJsonPath('meta.documents_status', 'incomplete')
            ->assertJsonPath('meta.documents_count', 1)
            ->assertJsonPath('meta.documents_missing_count', 5);
    }

    public function test_it_uploads_file_to_private_storage_with_checksum_and_download_requires_permission(): void
    {
        Storage::fake('local');
        $admission = $this->createApiUser(roleCode: 'admission');
        $teacher = $this->createApiUser(roleCode: 'teacher');
        $application = $this->makeApplication();
        $this->withApiAuth($admission);

        $response = $this->post("/api/admissions/{$application->id}/documents/passport/upload", [
            'file' => UploadedFile::fake()->createWithContent('passport.pdf', '%PDF-test'),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'under_review')
            ->assertJsonPath('data.files_count', 1);

        $file = ApplicantDocumentFile::firstOrFail();
        $this->assertStringStartsWith('applicant-documents/', $file->stored_path);
        Storage::disk('local')->assertExists($file->stored_path);
        $this->assertSame(hash('sha256', '%PDF-test'), $file->checksum_sha256);
        $this->assertDatabaseHas('audit_logs', ['module' => 'Admissions', 'action' => 'document_file_uploaded']);

        $document = $file->document;
        $this->withApiAuth($teacher)
            ->get("/api/admissions/{$application->id}/documents/{$document->id}/files/{$file->id}/download")
            ->assertForbidden();

        $this->withApiAuth($admission)
            ->get("/api/admissions/{$application->id}/documents/{$document->id}/files/{$file->id}/download")
            ->assertOk();
    }

    public function test_it_rejects_invalid_uploads_and_supports_multiple_files(): void
    {
        Storage::fake('local');
        $this->withApiAuth($this->createApiUser(roleCode: 'admission'));
        $application = $this->makeApplication();

        $this->post("/api/admissions/{$application->id}/documents/passport/upload", [
            'file' => UploadedFile::fake()->createWithContent('script.php', '<?php echo 1;'),
        ])->assertUnprocessable();

        $this->post("/api/admissions/{$application->id}/documents/passport/upload", [
            'file' => UploadedFile::fake()->create('too-large.pdf', 11 * 1024, 'application/pdf'),
        ])->assertUnprocessable();

        $this->post("/api/admissions/{$application->id}/documents/passport/upload", [
            'file' => UploadedFile::fake()->createWithContent('passport-1.pdf', '%PDF-1'),
        ])->assertOk();
        $this->post("/api/admissions/{$application->id}/documents/passport/upload", [
            'file' => UploadedFile::fake()->createWithContent('passport-2.pdf', '%PDF-2'),
        ])->assertOk();

        $this->assertSame(2, ApplicantDocumentFile::count());
    }

    public function test_it_verifies_and_rejects_documents_and_rejected_is_not_complete(): void
    {
        $this->withApiAuth($this->createApiUser(roleCode: 'admission'));
        $application = $this->makeApplication();

        $this->postJson("/api/admissions/{$application->id}/documents/passport/receive")->assertOk();
        $document = ApplicantApplicationDocument::where('type', 'passport')->firstOrFail();

        $this->postJson("/api/admissions/{$application->id}/documents/{$document->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        $this->postJson("/api/admissions/{$application->id}/documents/{$document->id}/reject", [
            'rejection_reason' => 'Нечитаемая копия',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->getJson("/api/admissions/{$application->id}/documents")
            ->assertOk()
            ->assertJsonPath('meta.documents_status', 'no_documents')
            ->assertJsonPath('meta.documents_count', 0);
    }

    public function test_sync_command_dry_run_does_not_write_and_apply_is_idempotent(): void
    {
        $this->makeApplication();
        $this->assertSame(0, ApplicantApplicationDocument::count());

        Artisan::call('admissions:sync-document-registry', ['--dry-run' => true]);
        $this->assertSame(0, ApplicantApplicationDocument::count());

        Artisan::call('admissions:sync-document-registry', ['--apply' => true]);
        $this->assertSame(6, ApplicantApplicationDocument::count());

        Artisan::call('admissions:sync-document-registry', ['--apply' => true]);
        $this->assertSame(6, ApplicantApplicationDocument::count());
    }

    private function makeApplication(): ApplicantApplication
    {
        $specialty = Specialty::create([
            'code' => '53.02.04',
            'name' => 'Вокальное искусство',
            'education_level' => 'СПО',
        ]);
        $program = EducationProgram::create([
            'specialty_id' => $specialty->id,
            'name' => 'Вокальное искусство',
            'year_start' => 2026,
            'study_form' => 'Очная',
            'study_years' => 4.0,
        ]);

        return ApplicantApplication::create([
            'education_program_id' => $program->id,
            'last_name' => 'Абитуриент',
            'first_name' => 'Тестовый',
            'birth_date' => '2010-01-01',
            'email' => uniqid('applicant', true).'@example.test',
            'education_base' => 'after_9',
            'status' => 'new',
            'submitted_at' => '2026-07-11',
        ]);
    }
}
