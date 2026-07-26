<?php

namespace Tests\Feature\Admissions;

use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\Applicant;
use App\Models\EducationProgram;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Specialty;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProgramChoiceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admission_user_can_create_list_update_and_delete_choices(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createApplication();
        $program = $this->createProgram('09.02.31', 'API choice program');
        $secondProgram = $this->createProgram('09.02.32', 'API choice second program');

        $choiceId = $this->withApiAuth($user)
            ->postJson("/api/admissions/applications/{$application->id}/choices", [
                'priority' => 1,
                'education_program_id' => $program->id,
                'education_form_id' => $this->referenceItemId('education_forms', 'full_time'),
                'funding_form_id' => $this->referenceItemId('funding_forms', 'budget'),
                'base_education_type_id' => $this->referenceItemId('base_education_types', 'basic_general'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.priority', 1)
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.education_program.id', $program->id)
            ->json('data.id');

        $this->withApiAuth($user)
            ->getJson("/api/admissions/applications/{$application->id}/choices")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $choiceId);

        $this->withApiAuth($user)
            ->patchJson("/api/admissions/choices/{$choiceId}", [
                'education_program_id' => $secondProgram->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.education_program.id', $secondProgram->id);

        $this->withApiAuth($user)
            ->deleteJson("/api/admissions/choices/{$choiceId}")
            ->assertNoContent();

        $this->assertDatabaseHas('applicant_application_choices', [
            'id' => $choiceId,
            'application_id' => $application->id,
        ]);
        $this->assertNotNull(\App\Models\Admissions\ProgramChoice::query()->find($choiceId)?->archived_at);
    }

    public function test_choice_api_rejects_duplicate_priority_and_duplicate_program(): void
    {
        $user = $this->createApiUser(roleCode: 'admission');
        $application = $this->createApplication();
        $program = $this->createProgram('09.02.33', 'Duplicate choice program');

        $this->withApiAuth($user)
            ->postJson("/api/admissions/applications/{$application->id}/choices", [
                'priority' => 1,
                'education_program_id' => $program->id,
            ])
            ->assertCreated();

        $this->withApiAuth($user)
            ->postJson("/api/admissions/applications/{$application->id}/choices", [
                'priority' => 1,
                'education_program_id' => $this->createProgram('09.02.34', 'Duplicate priority program')->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['priority']);

        $this->withApiAuth($user)
            ->postJson("/api/admissions/applications/{$application->id}/choices", [
                'priority' => 2,
                'education_program_id' => $program->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['education_program_id']);
    }

    public function test_student_cannot_access_program_choices(): void
    {
        $student = $this->createApiUser(roleCode: 'student');
        $application = $this->createApplication();

        $this->withApiAuth($student)
            ->getJson("/api/admissions/applications/{$application->id}/choices")
            ->assertForbidden();
    }

    public function test_choice_permissions_are_registered_for_roles(): void
    {
        foreach ([
            'admissions.choice.view',
            'admissions.choice.create',
            'admissions.choice.update',
            'admissions.choice.delete',
        ] as $code) {
            $this->assertDatabaseHas('permissions', [
                'code' => $code,
                'module' => 'Admissions',
                'active' => true,
            ]);
        }
    }

    private function createApplication(array $overrides = []): AdmissionApplication
    {
        $applicant = $this->createApplicant();

        return AdmissionApplication::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $applicant->id,
            'person_id' => $applicant->person_id,
            'admission_year' => 2026,
            'education_program_id' => $this->createProgram('09.02.30', 'Application base program')->id,
            'last_name' => $applicant->person->last_name,
            'first_name' => $applicant->person->first_name,
            'education_base' => 'after_9',
            'status' => AdmissionApplication::STATUS_DRAFT,
            'status_id' => $this->referenceItemId('admission_application_statuses', AdmissionApplication::STATUS_DRAFT),
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
            'submitted_at' => '2026-07-01',
        ], $overrides));
    }

    private function createApplicant(): Applicant
    {
        $person = Person::query()->create([
            'last_name' => 'ApiChoices',
            'first_name' => 'Applicant',
            'status' => 'active',
        ]);

        return Applicant::query()->create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'status_id' => $this->referenceItemId('applicant_statuses', 'active'),
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
        ])->load('person');
    }

    private function createProgram(string $code, string $name): EducationProgram
    {
        $specialty = Specialty::query()->create([
            'code' => $code,
            'name' => $name,
        ]);

        return EducationProgram::query()->create([
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
