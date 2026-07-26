<?php

namespace Tests\Unit\Admissions;

use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\Applicant;
use App\Models\Admissions\ProgramChoice;
use App\Models\AuditLog;
use App\Models\EducationProgram;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Setting;
use App\Models\Specialty;
use App\Services\Admissions\ProgramChoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProgramChoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_choice_for_foundation_application(): void
    {
        $service = app(ProgramChoiceService::class);
        $application = $this->createApplication();
        $program = $this->createProgram('09.02.21', 'Тестовая программа choices');

        $choice = $service->create($application->id, [
            'priority' => 1,
            'education_program_id' => $program->id,
            'education_form_id' => $this->referenceItemId('education_forms', 'full_time'),
            'funding_form_id' => $this->referenceItemId('funding_forms', 'budget'),
            'base_education_type_id' => $this->referenceItemId('base_education_types', 'basic_general'),
            'quota_type_id' => $this->referenceItemId('quota_types', 'general'),
        ]);

        $this->assertSame($application->id, $choice->application_id);
        $this->assertSame(1, $choice->priority);
        $this->assertTrue($choice->is_primary);
        $this->assertSame($program->id, $choice->education_program_id);
        $this->assertSame($program->specialty_id, $choice->specialty_id);
        $this->assertTrue(AuditLog::query()->where('action', 'program_choice_created')->exists());
    }

    public function test_it_rejects_duplicate_priority_and_duplicate_program(): void
    {
        $service = app(ProgramChoiceService::class);
        $application = $this->createApplication();
        $program = $this->createProgram('09.02.22', 'Первая программа choices');

        $service->create($application->id, [
            'priority' => 1,
            'education_program_id' => $program->id,
        ]);

        try {
            $service->create($application->id, [
                'priority' => 1,
                'education_program_id' => $this->createProgram('09.02.23', 'Вторая программа choices')->id,
            ]);
            $this->fail('Duplicate priority was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('priority', $exception->errors());
        }

        try {
            $service->create($application->id, [
                'priority' => 2,
                'education_program_id' => $program->id,
            ]);
            $this->fail('Duplicate education program was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('education_program_id', $exception->errors());
        }
    }

    public function test_it_rejects_status_from_wrong_reference_catalog(): void
    {
        $service = app(ProgramChoiceService::class);
        $application = $this->createApplication();

        $this->expectException(ValidationException::class);
        $service->create($application->id, [
            'priority' => 1,
            'education_program_id' => $this->createProgram('09.02.35', 'Wrong status catalog program')->id,
            'status_id' => $this->referenceItemId('education_forms', 'full_time'),
        ]);
    }

    public function test_it_rejects_priority_gaps(): void
    {
        $service = app(ProgramChoiceService::class);
        $application = $this->createApplication();

        $this->expectException(ValidationException::class);
        $service->create($application->id, [
            'priority' => 2,
            'education_program_id' => $this->createProgram('09.02.24', 'Gap program choices')->id,
        ]);
    }

    public function test_it_rejects_choices_above_configured_maximum(): void
    {
        $service = app(ProgramChoiceService::class);
        $application = $this->createApplication();

        Setting::query()->updateOrCreate(
            ['group' => 'admissions', 'key' => 'max_choices_per_application'],
            ['value' => 1, 'type' => 'integer', 'is_public' => false],
        );

        $service->create($application->id, [
            'priority' => 1,
            'education_program_id' => $this->createProgram('09.02.24', 'Limit first program choices')->id,
        ]);

        $this->expectException(ValidationException::class);
        $service->create($application->id, [
            'priority' => 2,
            'education_program_id' => $this->createProgram('09.02.28', 'Limit second program choices')->id,
        ]);
    }

    public function test_it_compacts_priorities_after_delete(): void
    {
        $service = app(ProgramChoiceService::class);
        $application = $this->createApplication();
        $first = $service->create($application->id, [
            'priority' => 1,
            'education_program_id' => $this->createProgram('09.02.25', 'Первая для удаления')->id,
        ]);
        $second = $service->create($application->id, [
            'priority' => 2,
            'education_program_id' => $this->createProgram('09.02.26', 'Вторая для удаления')->id,
        ]);

        $service->delete($first->id);

        $this->assertNotNull($first->fresh()->archived_at);
        $this->assertSame(1, $second->fresh()->priority);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertTrue(AuditLog::query()->where('action', 'program_choice_deleted')->exists());
    }

    public function test_it_allows_reusing_archived_program_and_priority(): void
    {
        $service = app(ProgramChoiceService::class);
        $application = $this->createApplication();
        $program = $this->createProgram('09.02.29', 'Archived reusable program');
        $choice = $service->create($application->id, [
            'priority' => 1,
            'education_program_id' => $program->id,
        ]);

        $service->delete($choice->id);

        $newChoice = $service->create($application->id, [
            'priority' => 1,
            'education_program_id' => $program->id,
        ]);

        $this->assertNotSame($choice->id, $newChoice->id);
        $this->assertSame(1, $newChoice->priority);
        $this->assertSame($program->id, $newChoice->education_program_id);
    }

    public function test_it_rejects_changes_for_registered_application(): void
    {
        $service = app(ProgramChoiceService::class);
        $application = $this->createApplication(['status' => AdmissionApplication::STATUS_REGISTERED]);

        $this->expectException(ValidationException::class);
        $service->create($application->id, [
            'priority' => 1,
            'education_program_id' => $this->createProgram('09.02.27', 'Registered application choice')->id,
        ]);
    }

    private function createApplication(array $overrides = []): AdmissionApplication
    {
        $applicant = $this->createApplicant();
        $status = $overrides['status'] ?? AdmissionApplication::STATUS_DRAFT;

        return AdmissionApplication::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $applicant->id,
            'person_id' => $applicant->person_id,
            'admission_year' => 2026,
            'education_program_id' => $this->createProgram('09.02.20', 'Базовая программа заявления')->id,
            'last_name' => $applicant->person->last_name,
            'first_name' => $applicant->person->first_name,
            'education_base' => 'after_9',
            'status' => $status,
            'status_id' => $this->referenceItemId('admission_application_statuses', $status),
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
            'submitted_at' => '2026-07-01',
        ], $overrides));
    }

    private function createApplicant(): Applicant
    {
        $person = Person::query()->create([
            'last_name' => 'Choices',
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
