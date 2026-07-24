<?php

namespace Tests\Unit\Admissions;

use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\Applicant;
use App\Models\AuditLog;
use App\Models\EducationProgram;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Specialty;
use App\Services\Admissions\AdmissionApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unit-тесты жизненного цикла foundation-заявления приемной комиссии.
 */
class AdmissionApplicationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_draft_application_for_applicant(): void
    {
        $service = app(AdmissionApplicationService::class);
        $applicant = $this->createApplicant();
        $program = $this->createProgram();

        $application = $service->createDraft([
            'applicant_id' => $applicant->id,
            'admission_year' => 2026,
            'education_program_id' => $program->id,
            'application_number' => 'DRAFT-1',
        ]);

        $this->assertSame($applicant->id, $application->applicant_id);
        $this->assertSame(2026, $application->admission_year);
        $this->assertSame('draft', $application->statusCode());
        $this->assertSame('DRAFT-1', $application->application_number);
        $this->assertSame($applicant->person->last_name, $application->last_name);
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'Admissions',
            'action' => 'admission_application_created',
            'entity_type' => 'AdmissionApplication',
            'entity_id' => $application->id,
        ]);
    }

    public function test_applicant_can_have_several_applications(): void
    {
        $service = app(AdmissionApplicationService::class);
        $applicant = $this->createApplicant();
        $program = $this->createProgram();

        $service->createDraft(['applicant_id' => $applicant->id, 'admission_year' => 2026, 'education_program_id' => $program->id]);
        $service->createDraft(['applicant_id' => $applicant->id, 'admission_year' => 2027, 'education_program_id' => $program->id]);

        $this->assertSame(2, $applicant->admissionApplications()->count());
    }

    public function test_it_updates_only_draft_application(): void
    {
        $service = app(AdmissionApplicationService::class);
        $application = $service->createDraft([
            'applicant_id' => $this->createApplicant()->id,
            'admission_year' => 2026,
            'education_program_id' => $this->createProgram()->id,
        ]);

        $updated = $service->updateDraft($application, ['comment' => 'Черновик уточнен.']);

        $this->assertSame('Черновик уточнен.', $updated->comment);
        $this->assertTrue(AuditLog::query()->where('action', 'admission_application_updated')->exists());
    }

    public function test_it_registers_draft_and_repeated_registration_is_idempotent(): void
    {
        $service = app(AdmissionApplicationService::class);
        $application = $service->createDraft([
            'applicant_id' => $this->createApplicant()->id,
            'admission_year' => 2026,
            'education_program_id' => $this->createProgram()->id,
        ]);

        $registered = $service->register($application);
        $again = $service->register($registered);

        $this->assertSame('registered', $registered->statusCode());
        $this->assertSame($registered->id, $again->id);
        $this->assertNotEmpty($registered->application_number);
        $this->assertTrue(AuditLog::query()->where('action', 'admission_application_registered')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'admission_application_registration_reused')->exists());
    }

    public function test_it_rejects_forbidden_status_transition(): void
    {
        $service = app(AdmissionApplicationService::class);
        $application = $service->createDraft([
            'applicant_id' => $this->createApplicant()->id,
            'admission_year' => 2026,
            'education_program_id' => $this->createProgram()->id,
        ]);
        $service->register($application);

        $this->expectException(ValidationException::class);

        $service->updateDraft($application->fresh(), ['comment' => 'Нельзя менять зарегистрированное заявление.']);
    }

    public function test_duplicate_application_number_is_rejected_inside_admission_year(): void
    {
        $service = app(AdmissionApplicationService::class);
        $applicant = $this->createApplicant();
        $program = $this->createProgram();

        $service->createDraft([
            'applicant_id' => $applicant->id,
            'admission_year' => 2026,
            'education_program_id' => $program->id,
            'application_number' => '2026-0001',
        ]);

        $this->expectException(ValidationException::class);

        $service->createDraft([
            'applicant_id' => $applicant->id,
            'admission_year' => 2026,
            'education_program_id' => $program->id,
            'application_number' => '2026-0001',
        ]);
    }

    private function createApplicant(): Applicant
    {
        $person = Person::query()->create([
            'last_name' => 'Заявитель',
            'first_name' => 'Тестовый',
            'middle_name' => 'Безличный',
            'birth_date' => '2007-02-03',
            'phone' => '79000000000',
            'email' => 'foundation-application@example.test',
            'status' => 'active',
        ]);

        return Applicant::query()->create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'status_id' => $this->referenceItemId('applicant_statuses', 'active'),
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
        ]);
    }

    private function createProgram(): EducationProgram
    {
        $specialty = Specialty::query()->create([
            'code' => '09.02.07',
            'name' => 'Информационные системы и программирование',
        ]);

        return EducationProgram::query()->create([
            'specialty_id' => $specialty->id,
            'name' => 'ППССЗ Информационные системы',
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
