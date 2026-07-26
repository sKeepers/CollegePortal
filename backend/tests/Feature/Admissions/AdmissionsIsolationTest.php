<?php

namespace Tests\Feature\Admissions;

use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\Applicant;
use App\Models\ApplicantApplication;
use App\Models\EducationProgram;
use App\Models\Group;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Specialty;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdmissionsIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_legacy_endpoint_lists_only_legacy_records(): void
    {
        $admin = $this->createApiUser(roleCode: 'admin');
        $program = $this->createProgram();
        $legacy = $this->createLegacyApplication($program);
        $foundation = $this->createFoundationApplication($program);

        $this->withApiAuth($admin)
            ->getJson('/api/applicant-applications?per_page=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $legacy->id);

        $this->assertSame(ApplicantApplication::RECORD_TYPE_LEGACY, $legacy->fresh()->record_type);
        $this->assertSame(AdmissionApplication::RECORD_TYPE_FOUNDATION, $foundation->fresh()->record_type);
    }

    public function test_legacy_endpoint_rejects_foundation_record_binding(): void
    {
        $admin = $this->createApiUser(roleCode: 'admin');
        $program = $this->createProgram();
        $foundation = $this->createFoundationApplication($program);
        $group = Group::query()->create([
            'name' => 'ADM-ISO-101',
            'specialty' => 'Тестовая специальность',
            'education_program_id' => $program->id,
            'course' => 1,
            'year_start' => 2026,
        ]);

        $this->withApiAuth($admin)
            ->getJson("/api/applicant-applications/{$foundation->id}")
            ->assertNotFound();

        $this->withApiAuth($admin)
            ->patchJson("/api/applicant-applications/{$foundation->id}", ['comment' => 'Legacy не должен менять foundation.'])
            ->assertNotFound();

        $this->withApiAuth($admin)
            ->patchJson("/api/applicant-applications/{$foundation->id}/documents/passport", ['is_received' => true])
            ->assertNotFound();

        $this->withApiAuth($admin)
            ->postJson("/api/applicant-applications/{$foundation->id}/enroll", [
                'group_id' => $group->id,
                'enrollment_date' => '2026-09-01',
            ])
            ->assertNotFound();

        $this->withApiAuth($admin)
            ->deleteJson("/api/applicant-applications/{$foundation->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('applicant_applications', [
            'id' => $foundation->id,
            'record_type' => AdmissionApplication::RECORD_TYPE_FOUNDATION,
            'status' => AdmissionApplication::STATUS_DRAFT,
        ]);
    }

    public function test_foundation_endpoint_lists_only_foundation_records_and_rejects_legacy_ids(): void
    {
        $admissionUser = $this->createApiUser(roleCode: 'admission');
        $program = $this->createProgram();
        $legacy = $this->createLegacyApplication($program);
        $foundation = $this->createFoundationApplication($program);

        $this->withApiAuth($admissionUser)
            ->getJson('/api/admissions/applications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $foundation->id);

        $this->withApiAuth($admissionUser)
            ->getJson("/api/admissions/applications/{$legacy->id}")
            ->assertNotFound();

        $this->withApiAuth($admissionUser)
            ->patchJson("/api/admissions/applications/{$legacy->id}", ['comment' => 'Foundation не должен менять legacy.'])
            ->assertNotFound();

        $this->withApiAuth($admissionUser)
            ->postJson("/api/admissions/applications/{$legacy->id}/register")
            ->assertNotFound();

        $this->assertDatabaseHas('applicant_applications', [
            'id' => $legacy->id,
            'record_type' => ApplicantApplication::RECORD_TYPE_LEGACY,
            'status' => 'new',
        ]);
    }

    public function test_legacy_csv_import_cannot_update_foundation_record_by_id(): void
    {
        $admin = $this->createApiUser(roleCode: 'admin');
        $program = $this->createProgram();
        $foundation = $this->createFoundationApplication($program);

        $csv = implode("\n", [
            'id;education_program_id;education_program;specialty_code;last_name;first_name;middle_name;birth_date;phone;email;education_base;status;submitted_at;comment',
            "{$foundation->id};{$program->id};;09.02.99;Ошибка;Legacy;;2008-01-01;;legacy-foundation@example.test;after_9;accepted;2026-07-01;Попытка обновления foundation",
        ]);

        $this->withApiAuth($admin)
            ->post('/api/applicant-applications/import', [
                'file' => UploadedFile::fake()->createWithContent('applicant-applications.csv', $csv),
            ])
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.errors.0.line', 2);

        $this->assertDatabaseHas('applicant_applications', [
            'id' => $foundation->id,
            'record_type' => AdmissionApplication::RECORD_TYPE_FOUNDATION,
            'status' => AdmissionApplication::STATUS_DRAFT,
        ]);
    }

    private function createLegacyApplication(EducationProgram $program): ApplicantApplication
    {
        return ApplicantApplication::query()->create([
            'education_program_id' => $program->id,
            'last_name' => 'Legacy',
            'first_name' => 'Applicant',
            'email' => 'legacy-isolation@example.test',
            'education_base' => 'after_9',
            'status' => 'new',
            'submitted_at' => '2026-07-01',
        ]);
    }

    private function createFoundationApplication(EducationProgram $program): AdmissionApplication
    {
        $applicant = $this->createApplicant();

        return AdmissionApplication::query()->create([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $applicant->id,
            'person_id' => $applicant->person_id,
            'admission_year' => 2026,
            'application_number' => null,
            'education_program_id' => $program->id,
            'last_name' => $applicant->person->last_name,
            'first_name' => $applicant->person->first_name,
            'middle_name' => $applicant->person->middle_name,
            'birth_date' => $applicant->person->birth_date,
            'phone' => $applicant->person->phone,
            'email' => $applicant->person->email,
            'education_base' => 'after_9',
            'status' => AdmissionApplication::STATUS_DRAFT,
            'status_id' => $this->referenceItemId('admission_application_statuses', AdmissionApplication::STATUS_DRAFT),
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
            'submitted_at' => '2026-07-01',
        ]);
    }

    private function createApplicant(): Applicant
    {
        $person = Person::query()->create([
            'last_name' => 'Foundation',
            'first_name' => 'Applicant',
            'middle_name' => 'Isolation',
            'birth_date' => '2008-02-03',
            'phone' => '79000000001',
            'email' => 'foundation-isolation@example.test',
            'status' => 'active',
        ]);

        return Applicant::query()->create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'status_id' => $this->referenceItemId('applicant_statuses', 'active'),
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
        ])->load('person');
    }

    private function createProgram(): EducationProgram
    {
        $specialty = Specialty::query()->create([
            'code' => '09.02.99',
            'name' => 'Тестовая специальность изоляции',
        ]);

        return EducationProgram::query()->create([
            'specialty_id' => $specialty->id,
            'name' => 'Тестовая программа изоляции',
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
