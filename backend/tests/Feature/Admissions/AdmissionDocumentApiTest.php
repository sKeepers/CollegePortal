<?php

namespace Tests\Feature\Admissions;

use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\ApplicationDocumentSet;
use App\Models\Admissions\Applicant;
use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;
use App\Models\ApplicantApplication as LegacyApplicantApplication;
use App\Models\AuditLog;
use App\Models\EducationProgram;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Specialty;
use Database\Seeders\AdmissionReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdmissionDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(AdmissionReferenceSeeder::class);
    }

    public function test_admission_user_can_create_update_archive_identity_document_with_masked_resource(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createAdmissionApplicationFixture();
        $typeId = $this->referenceItemId('admission_identity_document_types', 'russian_passport');

        $id = $this->withApiAuth($user)
            ->postJson("/api/admissions/applicants/{$application->applicant_id}/identity-documents", [
                'document_type_id' => $typeId,
                'series' => '1234',
                'number' => '567890',
                'issue_date' => '2026-07-01',
                'issued_by' => 'Тестовый орган',
                'release_place' => 'Тестовое место',
                'is_primary' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.number_masked', '**7890')
            ->json('data.id');

        $this->withApiAuth($user)
            ->patchJson("/api/admissions/identity-documents/{$id}", [
                'verification_status' => IdentityDocument::STATUS_VERIFIED,
                'fis_uid' => 'identity-test-uid',
            ])
            ->assertOk()
            ->assertJsonPath('data.verification_status', IdentityDocument::STATUS_VERIFIED);

        $this->assertFalse(
            str_contains(json_encode(AuditLog::query()->latest('id')->first()?->new_values, JSON_UNESCAPED_UNICODE), '567890')
        );

        $this->withApiAuth($user)
            ->deleteJson("/api/admissions/identity-documents/{$id}")
            ->assertNoContent();

        $this->assertNotNull(IdentityDocument::query()->find($id)?->archived_at);
    }

    public function test_admission_user_can_create_education_document_and_resource_masks_number(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createAdmissionApplicationFixture();
        $typeId = $this->referenceItemId('admission_education_document_types', 'basic_general_certificate');

        $this->withApiAuth($user)
            ->postJson("/api/admissions/applicants/{$application->applicant_id}/education-documents", [
                'document_type_id' => $typeId,
                'series' => 'АБ',
                'number' => '1234567890',
                'issue_date' => '2026-06-20',
                'document_organization' => 'Демонстрационная школа',
                'graduation_year' => 2026,
                'average_score' => 4.75,
                'has_attachment' => true,
                'is_primary' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.number_masked', '******7890')
            ->assertJsonMissingPath('data.storage_path');
    }

    public function test_private_file_upload_download_and_archive_do_not_expose_storage_path(): void
    {
        Storage::fake('local');
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createAdmissionApplicationFixture();
        $document = $this->createIdentityDocument($application);

        $fileId = $this->withApiAuth($user)
            ->post("/api/admissions/identity-documents/{$document->id}/files", [
                'category' => 'main_spread',
                'application_id' => $application->id,
                'file' => UploadedFile::fake()->create('passport.pdf', 32, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonMissingPath('data.storage_path')
            ->assertJsonPath('data.category', 'main_spread')
            ->json('data.id');

        $storedPath = \App\Models\Admissions\AdmissionDocumentFile::query()->findOrFail($fileId)->storage_path;
        Storage::disk('local')->assertExists($storedPath);

        $this->withApiAuth($user)
            ->get("/api/admissions/document-files/{$fileId}/download")
            ->assertOk();

        $this->withApiAuth($user)
            ->deleteJson("/api/admissions/document-files/{$fileId}")
            ->assertNoContent();

        Storage::disk('local')->assertExists($storedPath);
        $this->assertNotNull(\App\Models\Admissions\AdmissionDocumentFile::query()->find($fileId)?->archived_at);
    }

    public function test_document_file_upload_rejects_wrong_mime_and_duplicate_hash(): void
    {
        Storage::fake('local');
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createAdmissionApplicationFixture();
        $document = $this->createIdentityDocument($application);

        $this->withApiAuth($user)
            ->post("/api/admissions/identity-documents/{$document->id}/files", [
                'file' => UploadedFile::fake()->create('payload.exe', 1, 'application/x-msdownload'),
            ])
            ->assertUnprocessable();

        $first = UploadedFile::fake()->create('scan.pdf', 1, 'application/pdf');
        $second = UploadedFile::fake()->create('scan.pdf', 1, 'application/pdf');

        $this->withApiAuth($user)
            ->post("/api/admissions/identity-documents/{$document->id}/files", ['file' => $first])
            ->assertCreated();

        $this->withApiAuth($user)
            ->post("/api/admissions/identity-documents/{$document->id}/files", ['file' => $second])
            ->assertUnprocessable();
    }

    public function test_document_readiness_separates_internal_review_and_fis_readiness(): void
    {
        Storage::fake('local');
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createAdmissionApplicationFixture();

        $this->withApiAuth($user)
            ->getJson("/api/admissions/applications/{$application->id}/document-readiness")
            ->assertOk()
            ->assertJsonPath('data.internal_complete', false)
            ->assertJsonPath('data.fis_data_ready', false);

        $this->withApiAuth($user)->patchJson("/api/admissions/applicants/{$application->applicant_id}/snils", [
            'snils' => '112-233-445 95',
        ])->assertOk();
        $identity = $this->createIdentityDocument($application, ['verification_status' => IdentityDocument::STATUS_VERIFIED]);
        $education = $this->createEducationDocument($application, ['verification_status' => EducationDocument::STATUS_VERIFIED]);

        $this->withApiAuth($user)->post("/api/admissions/identity-documents/{$identity->id}/files", [
            'file' => UploadedFile::fake()->create('identity.pdf', 8, 'application/pdf'),
        ])->assertCreated();
        $this->withApiAuth($user)->post("/api/admissions/education-documents/{$education->id}/files", [
            'file' => UploadedFile::fake()->create('education.pdf', 8, 'application/pdf'),
        ])->assertCreated();

        $this->withApiAuth($user)
            ->getJson("/api/admissions/applications/{$application->id}/document-readiness")
            ->assertOk()
            ->assertJsonPath('data.internal_complete', true)
            ->assertJsonPath('data.review_complete', true)
            ->assertJsonPath('data.fis_data_ready', false)
            ->assertJsonPath('data.fis.fis_mapping_ready', false);
    }

    public function test_application_document_show_does_not_create_link_row(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createAdmissionApplicationFixture();

        $this->assertSame(0, ApplicationDocumentSet::query()->count());

        $this->withApiAuth($user)
            ->getJson("/api/admissions/applications/{$application->id}/documents")
            ->assertOk()
            ->assertJsonPath('data.application_id', $application->id)
            ->assertJsonPath('data.id', null);

        $this->assertSame(0, ApplicationDocumentSet::query()->count());
    }

    public function test_application_references_specific_document_versions_after_registration(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createAdmissionApplicationFixture();
        $identity = $this->createIdentityDocument($application, ['verification_status' => IdentityDocument::STATUS_VERIFIED]);
        $education = $this->createEducationDocument($application, ['verification_status' => EducationDocument::STATUS_VERIFIED]);

        $this->withApiAuth($user)
            ->postJson("/api/admissions/applications/{$application->id}/register", ['confirm_required_fields' => false])
            ->assertOk()
            ->assertJsonPath('data.status.code', AdmissionApplication::STATUS_REGISTERED);

        $newIdentity = $this->createIdentityDocument($application, [
            'series' => '9999',
            'number' => '000111',
            'number_hash' => hash('sha256', '9999|000111'),
            'is_primary' => true,
        ]);
        $newEducation = $this->createEducationDocument($application, [
            'series' => 'ВГ',
            'number' => '777777',
            'number_hash' => hash('sha256', 'ВГ|777777'),
            'is_primary' => true,
        ]);

        $this->withApiAuth($user)
            ->getJson("/api/admissions/applications/{$application->id}/document-readiness")
            ->assertOk()
            ->assertJsonPath('data.linked_identity_document_id', $identity->id)
            ->assertJsonPath('data.linked_education_document_id', $education->id);

        $this->assertDatabaseHas('admission_application_documents', [
            'application_id' => $application->id,
            'identity_document_id' => $identity->id,
            'education_document_id' => $education->id,
        ]);
        $this->assertNotSame($identity->id, $newIdentity->id);
        $this->assertNotSame($education->id, $newEducation->id);
    }

    public function test_material_patch_of_registered_identity_document_creates_next_version(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createAdmissionApplicationFixture();
        $identity = $this->createIdentityDocument($application, ['verification_status' => IdentityDocument::STATUS_VERIFIED]);
        $education = $this->createEducationDocument($application, ['verification_status' => EducationDocument::STATUS_VERIFIED]);

        $this->withApiAuth($user)
            ->putJson("/api/admissions/applications/{$application->id}/identity-document", ['document_id' => $identity->id])
            ->assertCreated();
        $this->withApiAuth($user)
            ->putJson("/api/admissions/applications/{$application->id}/education-document", ['document_id' => $education->id])
            ->assertOk();
        $this->withApiAuth($user)
            ->postJson("/api/admissions/applications/{$application->id}/register", ['confirm_required_fields' => false])
            ->assertOk();

        $newId = $this->withApiAuth($user)
            ->patchJson("/api/admissions/identity-documents/{$identity->id}", [
                'series' => '4321',
                'number' => '999000',
            ])
            ->assertOk()
            ->assertJsonPath('data.previous_version_id', $identity->id)
            ->assertJsonPath('data.version_number', 2)
            ->json('data.id');

        $this->assertNotSame($identity->id, $newId);
        $this->assertNotNull(IdentityDocument::query()->find($identity->id)?->replaced_at);
        $this->assertDatabaseHas('admission_application_documents', [
            'application_id' => $application->id,
            'identity_document_id' => $identity->id,
        ]);
        $this->assertFalse(
            str_contains(json_encode(AuditLog::query()->latest('id')->first()?->new_values, JSON_UNESCAPED_UNICODE), '999000')
        );
    }

    public function test_material_patch_of_registered_education_document_creates_next_version(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createAdmissionApplicationFixture();
        $identity = $this->createIdentityDocument($application, ['verification_status' => IdentityDocument::STATUS_VERIFIED]);
        $education = $this->createEducationDocument($application, ['verification_status' => EducationDocument::STATUS_VERIFIED]);

        $this->withApiAuth($user)
            ->putJson("/api/admissions/applications/{$application->id}/identity-document", ['document_id' => $identity->id])
            ->assertCreated();
        $this->withApiAuth($user)
            ->putJson("/api/admissions/applications/{$application->id}/education-document", ['document_id' => $education->id])
            ->assertOk();
        $this->withApiAuth($user)
            ->postJson("/api/admissions/applications/{$application->id}/register", ['confirm_required_fields' => false])
            ->assertOk();

        $newId = $this->withApiAuth($user)
            ->patchJson("/api/admissions/education-documents/{$education->id}", [
                'number' => '654999',
                'qualification_name' => 'Тестовая квалификация',
                'speciality_name' => 'Тестовая специальность',
                'registration_number' => 'REG-2026-001',
                'is_nostrificated' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.previous_version_id', $education->id)
            ->assertJsonPath('data.version_number', 2)
            ->assertJsonPath('data.qualification_name', 'Тестовая квалификация')
            ->json('data.id');

        $this->assertNotSame($education->id, $newId);
        $this->assertNotNull(EducationDocument::query()->find($education->id)?->replaced_at);
        $this->assertDatabaseHas('admission_application_documents', [
            'application_id' => $application->id,
            'education_document_id' => $education->id,
        ]);
    }

    public function test_document_from_another_applicant_cannot_be_assigned_to_application(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createAdmissionApplicationFixture();
        $otherApplication = $this->createAdmissionApplicationFixture();
        $otherIdentity = $this->createIdentityDocument($otherApplication);

        $this->withApiAuth($user)
            ->putJson("/api/admissions/applications/{$application->id}/identity-document", ['document_id' => $otherIdentity->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document_id']);
    }

    public function test_files_of_registered_application_document_are_immutable(): void
    {
        Storage::fake('local');
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createAdmissionApplicationFixture();
        $identity = $this->createIdentityDocument($application, ['verification_status' => IdentityDocument::STATUS_VERIFIED]);
        $education = $this->createEducationDocument($application, ['verification_status' => EducationDocument::STATUS_VERIFIED]);

        $this->withApiAuth($user)
            ->putJson("/api/admissions/applications/{$application->id}/identity-document", ['document_id' => $identity->id])
            ->assertCreated();
        $this->withApiAuth($user)
            ->putJson("/api/admissions/applications/{$application->id}/education-document", ['document_id' => $education->id])
            ->assertOk();
        $this->withApiAuth($user)
            ->postJson("/api/admissions/applications/{$application->id}/register", ['confirm_required_fields' => false])
            ->assertOk();

        $this->withApiAuth($user)
            ->post("/api/admissions/identity-documents/{$identity->id}/files", [
                'file' => UploadedFile::fake()->create('late-passport.pdf', 8, 'application/pdf'),
            ])
            ->assertUnprocessable();
    }

    public function test_registration_with_required_documents_flag_rejects_incomplete_application(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createAdmissionApplicationFixture();

        $this->withApiAuth($user)
            ->postJson("/api/admissions/applications/{$application->id}/register", ['confirm_required_fields' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents']);
    }

    public function test_student_cannot_access_foundation_documents_and_legacy_record_is_not_bound(): void
    {
        $student = $this->createApiUser(roleCode: 'student');
        $admin = $this->createApiUser(roleCode: 'admin');
        $application = $this->createAdmissionApplicationFixture();
        $program = $this->createProgram('09.02.91', 'Legacy program');
        $legacy = LegacyApplicantApplication::query()->create([
            'education_program_id' => $program->id,
            'last_name' => 'Legacy',
            'first_name' => 'Only',
            'education_base' => 'after_9',
            'status' => 'new',
            'submitted_at' => '2026-07-01',
        ]);

        $this->withApiAuth($student)
            ->getJson("/api/admissions/applicants/{$application->applicant_id}/identity-documents")
            ->assertForbidden();

        $this->withApiAuth($admin)
            ->getJson("/api/admissions/applications/{$legacy->id}/document-readiness")
            ->assertNotFound();
    }

    private function createAdmissionApplicationFixture(array $overrides = []): AdmissionApplication
    {
        $applicant = $this->createApplicant();

        return AdmissionApplication::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $applicant->id,
            'person_id' => $applicant->person_id,
            'admission_year' => 2026,
            'education_program_id' => $this->createProgram('09.02.90', 'Документы foundation')->id,
            'last_name' => $applicant->person->last_name,
            'first_name' => $applicant->person->first_name,
            'education_base' => 'after_9',
            'status' => AdmissionApplication::STATUS_DRAFT,
            'status_id' => $this->referenceItemId('admission_application_statuses', AdmissionApplication::STATUS_DRAFT),
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
            'submitted_at' => '2026-07-01',
        ], $overrides))->load('applicant.person');
    }

    private function createApplicant(): Applicant
    {
        $person = Person::query()->create([
            'last_name' => 'Документ',
            'first_name' => 'Абитуриент',
            'birth_date' => '2008-03-04',
            'status' => 'active',
        ]);

        return Applicant::query()->create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'status_id' => $this->referenceItemId('applicant_statuses', 'active'),
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
        ])->load('person');
    }

    private function createIdentityDocument(AdmissionApplication $application, array $overrides = []): IdentityDocument
    {
        return IdentityDocument::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $application->applicant_id,
            'person_id' => $application->person_id,
            'document_type_id' => $this->referenceItemId('admission_identity_document_types', 'russian_passport'),
            'series' => '1234',
            'number' => '567890',
            'number_hash' => hash('sha256', '1234|567890'),
            'issue_date' => '2026-07-01',
            'issued_by' => 'Тестовый орган',
            'release_place' => 'Тестовое место',
            'is_primary' => true,
            'verification_status' => IdentityDocument::STATUS_RECEIVED,
        ], $overrides))->load(['activeFiles', 'documentType']);
    }

    private function createEducationDocument(AdmissionApplication $application, array $overrides = []): EducationDocument
    {
        return EducationDocument::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $application->applicant_id,
            'document_type_id' => $this->referenceItemId('admission_education_document_types', 'basic_general_certificate'),
            'series' => 'АБ',
            'number' => '123456',
            'number_hash' => hash('sha256', 'АБ|123456'),
            'issue_date' => '2026-06-20',
            'document_organization' => 'Демонстрационная школа',
            'graduation_year' => 2026,
            'is_primary' => true,
            'verification_status' => EducationDocument::STATUS_RECEIVED,
        ], $overrides))->load(['activeFiles', 'documentType']);
    }

    private function createProgram(string $code, string $name): EducationProgram
    {
        $specialty = Specialty::query()->updateOrCreate(['code' => $code], ['name' => $name]);

        return EducationProgram::query()->updateOrCreate([
            'specialty_id' => $specialty->id,
            'name' => $name,
            'year_start' => 2026,
            'study_form' => 'Очная',
        ]);
    }

    private function referenceItemId(string $catalogCode, string $itemCode): int
    {
        $catalog = ReferenceCatalog::query()->where('code', $catalogCode)->firstOrFail();

        return ReferenceItem::query()
            ->where('catalog_id', $catalog->id)
            ->where('code', $itemCode)
            ->firstOrFail()
            ->id;
    }
}
