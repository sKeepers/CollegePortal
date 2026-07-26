<?php

namespace Tests\Unit\Admissions;

use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\Applicant;
use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;
use App\Models\EducationProgram;
use App\Models\Person;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Specialty;
use App\Services\Admissions\FisAdmissionDocumentReadinessService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FisReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_missing_fis_mapping_and_never_marks_xml_ready_in_back_005(): void
    {
        $this->seed(RoleSeeder::class);
        $application = $this->createApplication();
        $identity = IdentityDocument::query()->create([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $application->applicant_id,
            'person_id' => $application->person_id,
            'document_type_id' => $this->referenceItemId('admission_identity_document_types', 'russian_passport'),
            'number' => '123456',
            'issue_date' => '2026-07-01',
            'release_place' => 'Тест',
            'is_primary' => true,
            'verification_status' => IdentityDocument::STATUS_VERIFIED,
        ]);
        $education = EducationDocument::query()->create([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $application->applicant_id,
            'document_type_id' => $this->referenceItemId('admission_education_document_types', 'basic_general_certificate'),
            'number' => '654321',
            'issue_date' => '2026-06-20',
            'document_organization' => 'Демонстрационная школа',
            'is_primary' => true,
            'verification_status' => EducationDocument::STATUS_VERIFIED,
        ]);

        $result = app(FisAdmissionDocumentReadinessService::class)->assess($application->load('applicant.person'), $identity, $education);

        $this->assertFalse($result['fis_data_ready']);
        $this->assertFalse($result['fis_mapping_ready']);
        $this->assertContains('TIdentityDocument', $result['supported_xsd_structures']);
        $this->assertContains('identity_document.fis_identity_document_type_id', $result['missing_mappings']);
        $this->assertContains('fis_xml_package_generation_is_out_of_scope_for_BACK_005', $result['blocking_reasons']);
    }

    private function createApplication(): AdmissionApplication
    {
        $person = Person::query()->create([
            'last_name' => 'Fis',
            'first_name' => 'Readiness',
            'snils' => '112-233-445 95',
            'snils_hash' => hash('sha256', '11223344595'),
            'status' => 'active',
        ]);
        $applicant = Applicant::query()->create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'status_id' => $this->referenceItemId('applicant_statuses', 'active'),
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
        ]);

        return AdmissionApplication::query()->create([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $applicant->id,
            'person_id' => $person->id,
            'admission_year' => 2026,
            'education_program_id' => $this->createProgram()->id,
            'last_name' => $person->last_name,
            'first_name' => $person->first_name,
            'education_base' => 'after_9',
            'status' => AdmissionApplication::STATUS_DRAFT,
            'status_id' => $this->referenceItemId('admission_application_statuses', AdmissionApplication::STATUS_DRAFT),
            'source_id' => $this->referenceItemId('admission_sources', 'manual'),
            'submitted_at' => '2026-07-01',
        ]);
    }

    private function createProgram(): EducationProgram
    {
        $specialty = Specialty::query()->create(['code' => '09.02.88', 'name' => 'ФИС readiness']);

        return EducationProgram::query()->create([
            'specialty_id' => $specialty->id,
            'name' => 'ФИС readiness',
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
