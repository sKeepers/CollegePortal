<?php

namespace Tests\Feature;

use App\Models\ApplicantApplication;
use App\Models\ApplicantApplicationDocument;
use App\Models\AuditLog;
use App\Models\DigitalIdentity;
use App\Models\EducationProgram;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\Student;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admissions_preview_does_not_change_database_and_apply_changes_status_with_audit(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->createApiUser(roleCode: 'admission'));
        $program = $this->createProgram();
        $application = $this->makeApplicantApplication($program, ['status' => 'new']);

        $this->postJson('/api/admissions/bulk/preview', [
            'ids' => [$application->id],
            'action' => 'change_status',
            'payload' => ['status' => 'accepted'],
        ])->assertOk()->assertJsonPath('data.will_change', 1);

        $this->assertDatabaseHas('applicant_applications', ['id' => $application->id, 'status' => 'new']);

        $this->postJson('/api/admissions/bulk/apply', [
            'ids' => [$application->id],
            'action' => 'change_status',
            'payload' => ['status' => 'accepted'],
        ])->assertOk()->assertJsonPath('data.changed', 1);

        $this->assertDatabaseHas('applicant_applications', ['id' => $application->id, 'status' => 'accepted']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'Admissions', 'action' => 'bulk_change_status']);
    }

    public function test_admissions_bulk_recommend_and_enroll_selected_without_student_duplicates(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->createApiUser(roleCode: 'admission'));
        $program = $this->createProgram();
        $group = $this->createGroup($program);
        $application = $this->makeApplicantApplication($program, ['status' => 'accepted', 'email' => 'bulk-applicant@example.test']);

        $this->postJson('/api/admissions/bulk/apply', [
            'ids' => [$application->id],
            'action' => 'mark_documents_provided',
            'payload' => [],
        ])->assertOk()->assertJsonPath('data.changed', 1);

        $this->assertTrue($application->refresh()->documents_provided);
        $this->assertSame(0, $application->documents()->count());
        $this->markDocumentsReceived($application, 6);

        $this->postJson('/api/admissions/bulk/apply', [
            'ids' => [$application->id],
            'action' => 'mark_recommended',
            'payload' => [],
        ])->assertOk()->assertJsonPath('data.changed', 1);

        $this->postJson('/api/admissions/bulk/apply', [
            'ids' => [$application->id],
            'action' => 'enroll_selected',
            'payload' => ['group_id' => $group->id, 'enrollment_date' => '2026-09-01'],
        ])->assertOk()->assertJsonPath('data.changed', 1);

        $this->assertDatabaseHas('students', ['email' => 'bulk-applicant@example.test', 'group_id' => $group->id]);

        $this->postJson('/api/admissions/bulk/apply', [
            'ids' => [$application->id],
            'action' => 'enroll_selected',
            'payload' => ['group_id' => $group->id, 'enrollment_date' => '2026-09-01'],
        ])->assertOk()->assertJsonPath('data.skipped', 1);

        $this->assertSame(1, Student::where('email', 'bulk-applicant@example.test')->count());
    }

    public function test_admissions_bulk_document_type_statuses_preview_and_apply(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->createApiUser(roleCode: 'admission'));
        $program = $this->createProgram();
        $application = $this->makeApplicantApplication($program);

        $this->postJson('/api/admissions/bulk/preview', [
            'ids' => [$application->id],
            'action' => 'mark_document_type_received',
            'payload' => ['document_type' => 'passport'],
        ])->assertOk()->assertJsonPath('data.will_change', 1);

        $this->assertSame(0, $application->documents()->count());

        $this->postJson('/api/admissions/bulk/apply', [
            'ids' => [$application->id],
            'action' => 'send_document_type_review',
            'payload' => ['document_type' => 'passport'],
        ])->assertOk()->assertJsonPath('data.changed', 1);

        $this->assertDatabaseHas('applicant_application_documents', [
            'applicant_application_id' => $application->id,
            'type' => 'passport',
            'status' => 'under_review',
        ]);

        $this->postJson('/api/admissions/bulk/apply', [
            'ids' => [$application->id],
            'action' => 'verify_document_type',
            'payload' => ['document_type' => 'passport'],
        ])->assertOk()->assertJsonPath('data.changed', 1);

        $this->assertDatabaseHas('applicant_application_documents', [
            'applicant_application_id' => $application->id,
            'type' => 'passport',
            'status' => 'verified',
        ]);

        $this->postJson('/api/admissions/bulk/apply', [
            'ids' => [$application->id],
            'action' => 'reject_document_type',
            'payload' => ['document_type' => 'passport', 'reason' => 'Нечитаемый файл'],
        ])->assertOk()->assertJsonPath('data.changed', 1);

        $this->assertDatabaseHas('applicant_application_documents', [
            'applicant_application_id' => $application->id,
            'type' => 'passport',
            'status' => 'rejected',
            'rejection_reason' => 'Нечитаемый файл',
        ]);
    }

    public function test_students_bulk_assign_status_archive_export_and_issue_passes_without_duplicates(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RoleSeeder::class);
        // Массовые операции по контингенту относятся к «Учебной части 2».
        $this->withApiAuth($this->createApiUser(roleCode: 'study_records'));
        $program = $this->createProgram();
        $group = $this->createGroup($program, 'M-101');
        $targetGroup = $this->createGroup($program, 'M-102');
        $person = Person::create(['last_name' => 'Иванов', 'first_name' => 'Иван', 'status' => 'active']);
        $student = Student::create([
            'person_id' => $person->id,
            'group_id' => $group->id,
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'status' => 'active',
        ]);

        $this->postJson('/api/students/bulk/apply', [
            'ids' => [$student->id],
            'action' => 'assign_group',
            'payload' => ['group_id' => $targetGroup->id],
        ])->assertOk()->assertJsonPath('data.changed', 1);
        $this->assertDatabaseHas('students', ['id' => $student->id, 'group_id' => $targetGroup->id]);

        $this->postJson('/api/students/bulk/apply', [
            'ids' => [$student->id],
            'action' => 'change_status',
            'payload' => ['status' => 'academic_leave'],
        ])->assertOk()->assertJsonPath('data.changed', 1);

        $this->postJson('/api/students/bulk/apply', [
            'ids' => [$student->id],
            'action' => 'issue_digital_passes',
            'payload' => [],
        ])->assertOk()->assertJsonPath('data.changed', 1);

        $this->postJson('/api/students/bulk/apply', [
            'ids' => [$student->id],
            'action' => 'issue_digital_passes',
            'payload' => [],
        ])->assertOk()->assertJsonPath('data.skipped', 1);

        $this->assertSame(1, DigitalIdentity::where('entity_type', 'student')->where('entity_id', $student->id)->where('status', 'active')->count());

        $this->postJson('/api/students/bulk/apply', [
            'ids' => [$student->id],
            'action' => 'archive_selected',
            'payload' => [],
        ])->assertOk()->assertJsonPath('data.changed', 1);
        $this->assertDatabaseHas('students', ['id' => $student->id, 'status' => 'archived']);

        $this->postJson('/api/students/bulk/apply', [
            'ids' => [$student->id],
            'action' => 'export_selected',
            'payload' => [],
        ])->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertDatabaseHas('audit_logs', ['module' => 'Students', 'action' => 'bulk_archive_selected']);
    }



    public function test_admissions_pagination_all_returns_all_records_and_kpi_ready_is_independent(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->createApiUser(roleCode: 'admission'));
        $program = $this->createProgram();

        for ($i = 1; $i <= 152; $i++) {
            $this->makeApplicantApplication($program, [
                'last_name' => sprintf('Пагинация%03d', $i),
                'email' => sprintf('pagination%03d@example.test', $i),
                'status' => $i === 1 ? 'ready_for_enrollment' : 'new',
                'documents_provided' => $i <= 100,
                'recommended_for_enrollment' => $i <= 7,
            ]);
        }

        $this->getJson('/api/applicant-applications?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 152)
            ->assertJsonPath('meta.per_page', 10);

        $this->getJson('/api/applicant-applications?per_page=20')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 152)
            ->assertJsonPath('meta.per_page', 20);

        $this->getJson('/api/applicant-applications?per_page=50')
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.total', 152)
            ->assertJsonPath('meta.per_page', 50);

        $this->getJson('/api/applicant-applications?per_page=0')
            ->assertOk()
            ->assertJsonCount(152, 'data');

        $this->getJson('/api/admissions/stats')
            ->assertOk()
            ->assertJsonPath('data.total', 152)
            ->assertJsonPath('data.documents_provided', 100)
            ->assertJsonPath('data.ready', 1)
            ->assertJsonPath('data.recommended', 7)
            ->assertJsonPath('data.enrolled', 0);
    }

    public function test_admissions_stats_and_bulk_filter_selection_cover_all_records_not_current_page(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->createApiUser(roleCode: 'admission'));
        $program = $this->createProgram();

        for ($i = 1; $i <= 152; $i++) {
            $this->makeApplicantApplication($program, [
                'last_name' => sprintf('Абитуриент%03d', $i),
                'email' => sprintf('applicant%03d@example.test', $i),
                'status' => $i <= 10 ? 'enrolled' : 'new',
                'documents_provided' => false,
            ]);
        }

        $this->getJson('/api/admissions/stats')
            ->assertOk()
            ->assertJsonPath('data.total', 152)
            ->assertJsonPath('data.no_documents', 152)
            ->assertJsonPath('data.incomplete', 0)
            ->assertJsonPath('data.enrolled', 10);

        $firstPageIds = ApplicantApplication::query()->orderBy('id')->take(50)->pluck('id')->all();
        $this->postJson('/api/admissions/bulk/apply', [
            'ids' => $firstPageIds,
            'selection_scope' => 'current_page',
            'action' => 'mark_documents_provided',
            'payload' => [],
        ])->assertOk()
            ->assertJsonPath('data.scope', 'current_page')
            ->assertJsonPath('data.changed', 50);

        $this->assertSame(50, ApplicantApplication::where('documents_provided', true)->count());
        $this->assertSame(102, ApplicantApplication::where(fn ($query) => $query->where('documents_provided', false)->orWhereNull('documents_provided'))->count());

        $this->getJson('/api/admissions/stats')
            ->assertOk()
            ->assertJsonPath('data.total', 152)
            ->assertJsonPath('data.no_documents', 152)
            ->assertJsonPath('data.incomplete', 0)
            ->assertJsonPath('data.complete', 0)
            ->assertJsonPath('data.documents_provided', 50);

        $preview = $this->postJson('/api/admissions/bulk/preview', [
            'filter' => ['page' => 1, 'per_page' => 50, 'rowsPerPage' => 50, 'limit' => 50],
            'selection_scope' => 'filter',
            'action' => 'mark_documents_provided',
            'payload' => [],
        ])->assertOk()
            ->assertJsonPath('data.scope', 'filter')
            ->assertJsonPath('data.found', 152)
            ->assertJsonPath('data.will_change', 102)
            ->assertJsonPath('data.already_set', 50)
            ->json('data');

        $this->assertSame(50, $preview['skipped']);

        $this->postJson('/api/admissions/bulk/apply', [
            'filter' => ['page' => 1, 'per_page' => 50, 'rowsPerPage' => 50, 'limit' => 50],
            'selection_scope' => 'filter',
            'action' => 'mark_documents_provided',
            'payload' => [],
        ])->assertOk()
            ->assertJsonPath('data.scope', 'filter')
            ->assertJsonPath('data.changed', 102)
            ->assertJsonPath('data.already_set', 50);

        $this->assertSame(152, ApplicantApplication::where('documents_provided', true)->count());
        $this->getJson('/api/admissions/stats')
            ->assertOk()
            ->assertJsonPath('data.no_documents', 152)
            ->assertJsonPath('data.incomplete', 0)
            ->assertJsonPath('data.complete', 0)
            ->assertJsonPath('data.documents_provided', 152);
    }

    public function test_admissions_bulk_document_confirmation_does_not_create_documents(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->createApiUser(roleCode: 'admission'));
        $program = $this->createProgram();
        $application = $this->makeApplicantApplication($program, ['documents_provided' => false]);

        $this->postJson('/api/admissions/bulk/apply', [
            'ids' => [$application->id],
            'action' => 'mark_documents_provided',
            'payload' => [],
        ])->assertOk()
            ->assertJsonPath('data.changed', 1);

        $application->refresh();
        $this->assertTrue($application->documents_provided);
        $this->assertSame(0, $application->documents()->count());

        $stats = $this->getJson('/api/admissions/stats')->assertOk()->json('data');
        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['no_documents']);
        $this->assertSame(0, $stats['incomplete']);
        $this->assertSame(0, $stats['complete']);
        $this->assertSame(1, $stats['documents_provided']);
        $this->assertSame($stats['total'], $stats['no_documents'] + $stats['incomplete'] + $stats['complete']);
    }

    public function test_dashboard_admissions_without_documents_matches_admissions_aggregate(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->createApiUser(roleCode: 'admin'));
        $program = $this->createProgram();

        for ($i = 1; $i <= 3; $i++) {
            $this->makeApplicantApplication($program, [
                'last_name' => sprintf('Документы%d', $i),
                'email' => sprintf('docs%d@example.test', $i),
                'documents_provided' => $i === 1,
            ]);
        }

        $stats = $this->getJson('/api/admissions/stats')->assertOk()->json('data');
        $this->assertSame(3, $stats['no_documents']);
        $this->assertSame(0, $stats['incomplete']);
        $this->assertSame(1, $stats['documents_provided']);

        $dashboard = $this->getJson('/api/dashboard/analytics/executive')->assertOk()->json('data.attention');
        $withoutDocuments = collect($dashboard)->firstWhere('title', 'Заявления без документов');

        $this->assertSame($stats['no_documents'], $withoutDocuments['value']);
    }

    public function test_bulk_operations_require_exact_permissions(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RoleSeeder::class);
        $teacher = $this->createApiUser(roleCode: 'teacher');
        $director = $this->createApiUser(roleCode: 'director');
        $program = $this->createProgram();
        $application = $this->makeApplicantApplication($program);
        $group = $this->createGroup($program);
        $student = Student::create(['group_id' => $group->id, 'last_name' => 'Нет', 'first_name' => 'Прав', 'status' => 'active']);

        $this->withApiAuth($teacher)->postJson('/api/students/bulk/preview', [
            'ids' => [$student->id], 'action' => 'change_status', 'payload' => ['status' => 'expelled'],
        ])->assertForbidden();

        $this->withApiAuth($director)->postJson('/api/admissions/bulk/preview', [
            'ids' => [$application->id], 'action' => 'export_selected', 'payload' => [],
        ])->assertOk();

        $this->withApiAuth($director)->postJson('/api/admissions/bulk/preview', [
            'ids' => [$application->id], 'action' => 'change_status', 'payload' => ['status' => 'accepted'],
        ])->assertForbidden();
    }

    private function createProgram(): EducationProgram
    {
        $specialty = Specialty::create([
            'code' => '53.02.04',
            'name' => 'Вокальное искусство',
            'education_level' => 'СПО',
            'qualification' => 'Артист-вокалист',
            'normative_study_years' => 4.0,
        ]);

        return EducationProgram::create([
            'specialty_id' => $specialty->id,
            'name' => 'Вокальное искусство',
            'year_start' => 2026,
            'study_form' => 'Очная',
            'study_years' => 4.0,
            'is_active' => true,
        ]);
    }

    private function createGroup(EducationProgram $program, string $name = 'ВК-101'): Group
    {
        return Group::create([
            'name' => $name,
            'specialty' => 'Вокальное искусство',
            'education_program_id' => $program->id,
            'course' => 1,
            'year_start' => 2026,
        ]);
    }

    private function makeApplicantApplication(EducationProgram $program, array $overrides = []): ApplicantApplication
    {
        return ApplicantApplication::create(array_merge([
            'education_program_id' => $program->id,
            'last_name' => 'Анохин',
            'first_name' => 'Дмитрий',
            'middle_name' => 'Игоревич',
            'birth_date' => '2010-01-15',
            'phone' => '+79990000000',
            'email' => 'applicant@example.test',
            'education_base' => 'after_9',
            'education_form' => 'Очная',
            'funding_form' => 'Бюджет',
            'status' => 'new',
            'submitted_at' => '2026-07-01',
        ], $overrides));
    }

    private function markDocumentsReceived(ApplicantApplication $application, int $count = 3): void
    {
        foreach (array_slice(['passport', 'education_document', 'snils', 'personal_data_consent', 'photo', 'medical_certificate'], 0, $count) as $type) {
            ApplicantApplicationDocument::create([
                'applicant_application_id' => $application->id,
                'type' => $type,
                'title' => $type,
                'is_received' => true,
                'status' => 'received',
                'received_at' => now(),
            ]);
        }
    }
}
