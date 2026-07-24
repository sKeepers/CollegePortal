<?php

namespace Tests\Feature\Admissions;

use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\Applicant;
use App\Models\ApplicantApplication as LegacyApplicantApplication;
use App\Models\EducationProgram;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Specialty;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тесты read/write foundation API заявлений приемной комиссии.
 */
class AdmissionApplicationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_permission_can_create_and_read_draft_application(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->createApiUser(roleCode: 'admission');
        $applicant = $this->createApplicant();
        $program = $this->createProgram();

        $created = $this->withApiAuth($user)
            ->postJson('/api/admissions/applications', [
                'applicant_id' => $applicant->id,
                'admission_year' => 2026,
                'education_program_id' => $program->id,
                'application_number' => 'DRAFT-API-1',
                'comment' => 'Первичный черновик.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status.code', 'draft')
            ->assertJsonPath('data.application_number', 'DRAFT-API-1')
            ->json('data.id');

        $this->withApiAuth($user)
            ->getJson('/api/admissions/applications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $created);

        $this->withApiAuth($user)
            ->getJson("/api/admissions/applications/{$created}")
            ->assertOk()
            ->assertJsonPath('data.id', $created)
            ->assertJsonPath('data.applicant.display_name', 'Заявитель Тестовый Безличный');
    }

    public function test_api_does_not_expose_extra_person_data(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createFoundationApplication();

        $data = $this->withApiAuth($user)
            ->getJson("/api/admissions/applications/{$application->id}")
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('applicant', $data);
        $this->assertArrayNotHasKey('phone', $data['applicant']);
        $this->assertArrayNotHasKey('email', $data['applicant']);
        $this->assertArrayNotHasKey('birth_date', $data['applicant']);
    }

    public function test_user_without_permission_cannot_access_application_foundation(): void
    {
        $this->seed(RoleSeeder::class);
        $student = $this->createApiUser(roleCode: 'student');

        $this->withApiAuth($student)
            ->getJson('/api/admissions/applications')
            ->assertForbidden();
    }

    public function test_it_updates_draft_and_rejects_update_after_registration(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createFoundationApplication();

        $this->withApiAuth($user)
            ->patchJson("/api/admissions/applications/{$application->id}", ['comment' => 'Черновик изменен.'])
            ->assertOk()
            ->assertJsonPath('data.comment', 'Черновик изменен.');

        $this->withApiAuth($user)
            ->postJson("/api/admissions/applications/{$application->id}/register")
            ->assertOk()
            ->assertJsonPath('data.status.code', 'registered');

        $this->withApiAuth($user)
            ->patchJson("/api/admissions/applications/{$application->id}", ['comment' => 'Позднее изменение.'])
            ->assertUnprocessable();
    }

    public function test_registration_is_idempotent(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createFoundationApplication();

        $first = $this->withApiAuth($user)
            ->postJson("/api/admissions/applications/{$application->id}/register")
            ->assertOk()
            ->json('data.application_number');

        $this->withApiAuth($user)
            ->postJson("/api/admissions/applications/{$application->id}/register")
            ->assertOk()
            ->assertJsonPath('data.application_number', $first)
            ->assertJsonPath('data.status.code', 'registered');
    }

    public function test_filters_by_applicant_status_year_and_number(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->createApiUser(roleCode: 'admission');
        $first = $this->createFoundationApplication(['admission_year' => 2026, 'application_number' => 'A-2026']);
        $this->createFoundationApplication(['admission_year' => 2027, 'application_number' => 'B-2027']);

        $this->withApiAuth($user)
            ->getJson("/api/admissions/applications?applicant_id={$first->applicant_id}&status=draft&admission_year=2026&q=A-2026")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id);
    }

    public function test_no_delete_endpoint_is_exposed(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->createApiUser(roleCode: 'admin');
        $application = $this->createFoundationApplication();

        $this->withApiAuth($admin)
            ->deleteJson("/api/admissions/applications/{$application->id}")
            ->assertStatus(405);
    }

    public function test_legacy_applicant_applications_endpoint_still_works(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->createApiUser(roleCode: 'admin');
        $program = $this->createProgram();

        LegacyApplicantApplication::query()->create([
            'education_program_id' => $program->id,
            'last_name' => 'Legacy',
            'first_name' => 'Applicant',
            'education_base' => 'after_9',
            'status' => 'new',
            'submitted_at' => '2026-07-01',
        ]);
        $this->createFoundationApplication(['education_program_id' => $program->id]);

        $this->withApiAuth($admin)
            ->getJson('/api/applicant-applications')
            ->assertOk();
    }

    public function test_application_permissions_are_registered_for_roles(): void
    {
        $this->seed(RoleSeeder::class);

        foreach ([
            'admissions.application.view',
            'admissions.application.create',
            'admissions.application.update',
            'admissions.application.register',
            'admissions.application.manage',
        ] as $code) {
            $this->assertDatabaseHas('permissions', [
                'code' => $code,
                'module' => 'Admissions',
                'active' => true,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createFoundationApplication(array $overrides = []): AdmissionApplication
    {
        $applicant = $this->createApplicant();
        $program = isset($overrides['education_program_id'])
            ? EducationProgram::query()->findOrFail($overrides['education_program_id'])
            : $this->createProgram();

        return AdmissionApplication::query()->create(array_merge([
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
            'status' => 'draft',
            'status_id' => $this->referenceItemId('admission_application_statuses', 'draft'),
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
            'submitted_at' => '2026-07-01',
        ], $overrides))->load(['applicant.person', 'statusItem', 'source', 'educationProgram.specialty']);
    }

    private function createApplicant(): Applicant
    {
        $person = Person::query()->create([
            'last_name' => 'Заявитель',
            'first_name' => 'Тестовый',
            'middle_name' => 'Безличный',
            'birth_date' => '2007-02-03',
            'phone' => '79000000000',
            'email' => 'foundation-api@example.test',
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
        $nextCode = Specialty::query()->count() + 1;
        $specialty = Specialty::query()->create([
            'code' => '09.02.'.str_pad((string) $nextCode, 2, '0', STR_PAD_LEFT),
            'name' => 'Информационные системы и программирование',
        ]);

        return EducationProgram::query()->create([
            'specialty_id' => $specialty->id,
            'name' => 'ППССЗ Информационные системы '.($specialty->id),
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
