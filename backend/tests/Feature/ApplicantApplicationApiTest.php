<?php

namespace Tests\Feature;

use App\Models\ApplicantApplication;
use App\Models\ApplicantApplicationDocument;
use App\Models\EducationProgram;
use App\Models\Group;
use App\Models\Specialty;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ApplicantApplicationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_lists_applicant_applications(): void
    {
        $program = $this->createProgram();
        ApplicantApplication::create($this->payload($program));

        $this->getJson('/api/applicant-applications')
            ->assertOk()
            ->assertJsonPath('data.0.last_name', 'Анохин')
            ->assertJsonPath('data.0.education_program.specialty.code', '53.02.04');
    }

    public function test_it_creates_applicant_application(): void
    {
        $program = $this->createProgram();

        $response = $this->postJson('/api/applicant-applications', $this->payload($program));

        $response
            ->assertCreated()
            ->assertJsonPath('data.first_name', 'Дмитрий')
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.education_program.id', $program->id)
            ->assertJsonPath('data.events.0.type', 'created')
            ->assertJsonPath('data.documents_total_count', 6)
            ->assertJsonPath('data.documents_received_count', 0);

        $this->assertDatabaseHas('applicant_applications', [
            'last_name' => 'Анохин',
            'education_base' => 'after_9',
        ]);
        $this->assertDatabaseHas('applicant_application_events', [
            'type' => 'created',
            'title' => 'Создано заявление',
        ]);
        $this->assertDatabaseHas('applicant_application_documents', [
            'type' => 'passport',
            'title' => 'Паспорт',
            'is_received' => false,
        ]);
    }

    public function test_it_filters_applicant_applications_by_status(): void
    {
        $program = $this->createProgram();
        ApplicantApplication::create($this->payload($program, ['status' => 'new']));
        ApplicantApplication::create($this->payload($program, [
            'last_name' => 'Борисова',
            'email' => 'borisova@example.test',
            'status' => 'accepted',
        ]));

        $this->getJson('/api/applicant-applications?status=accepted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Борисова');
    }

    public function test_it_updates_applicant_application(): void
    {
        $program = $this->createProgram();
        $application = ApplicantApplication::create($this->payload($program));

        $this->patchJson("/api/applicant-applications/{$application->id}", [
            'status' => 'accepted',
            'comment' => 'Документы приняты.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.comment', 'Документы приняты.');

        $this->assertDatabaseHas('applicant_application_events', [
            'applicant_application_id' => $application->id,
            'type' => 'status_changed',
        ]);
        $this->assertDatabaseHas('applicant_application_events', [
            'applicant_application_id' => $application->id,
            'type' => 'comment_changed',
        ]);
    }

    public function test_it_exports_applicant_applications_to_csv_with_filters(): void
    {
        $program = $this->createProgram();
        ApplicantApplication::create($this->payload($program, ['status' => 'new']));
        ApplicantApplication::create($this->payload($program, [
            'last_name' => 'Борисова',
            'email' => 'borisova@example.test',
            'status' => 'accepted',
        ]));

        $response = $this->get('/api/applicant-applications/export?status=accepted');

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('Борисова', $content);
        $this->assertStringNotContainsString('Анохин', $content);
    }

    public function test_it_imports_applicant_applications_from_csv(): void
    {
        $program = $this->createProgram();
        $existing = ApplicantApplication::create($this->payload($program));

        $csv = implode("\n", [
            'id;education_program_id;education_program;specialty_code;last_name;first_name;middle_name;birth_date;phone;email;education_base;status;submitted_at;comment',
            "{$existing->id};;ППССЗ Вокальное искусство;53.02.04;Анохин;Дмитрий;Алексеевич;14.03.2010;+79990000010;applicant@example.test;после 9 класса;принято;25.06.2026;Документы приняты",
            ";;ППССЗ Вокальное искусство;53.02.04;Борисова;Софья;Владимировна;2009-11-02;+79990000011;borisova@example.test;after_11;new;2026-06-26;Новое заявление",
        ]);

        $response = $this->post('/api/applicant-applications/import', [
            'file' => UploadedFile::fake()->createWithContent('applicant-applications.csv', $csv),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.errors', []);

        $this->assertDatabaseHas('applicant_applications', [
            'id' => $existing->id,
            'status' => 'accepted',
            'submitted_at' => '2026-06-25 00:00:00',
        ]);
        $this->assertDatabaseHas('applicant_applications', [
            'last_name' => 'Борисова',
            'education_base' => 'after_11',
        ]);
        $this->assertDatabaseHas('applicant_application_events', [
            'applicant_application_id' => $existing->id,
            'type' => 'imported',
            'title' => 'Обновлено из CSV',
        ]);
    }

    public function test_it_returns_line_errors_for_invalid_applicant_application_rows(): void
    {
        $csv = implode("\n", [
            'id;education_program_id;education_program;specialty_code;last_name;first_name;middle_name;birth_date;phone;email;education_base;status;submitted_at;comment',
            ';;Несуществующая программа;53.02.04;Анохин;Дмитрий;;wrong-date;;bad-email;после 12 класса;непонятно;;',
        ]);

        $response = $this->post('/api/applicant-applications/import', [
            'file' => UploadedFile::fake()->createWithContent('applicant-applications.csv', $csv),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.errors.0.line', 2);
    }

    public function test_it_filters_applicant_applications_by_document_status(): void
    {
        $program = $this->createProgram();
        $noDocuments = ApplicantApplication::create($this->payload($program, [
            'last_name' => 'БезДокументов',
            'email' => 'no-documents@example.test',
            'documents_provided' => true,
        ]));
        $incomplete = ApplicantApplication::create($this->payload($program, [
            'last_name' => 'Неполный',
            'email' => 'incomplete-documents@example.test',
            'documents_provided' => true,
        ]));
        $complete = ApplicantApplication::create($this->payload($program, [
            'last_name' => 'Полный',
            'email' => 'complete-documents@example.test',
            'documents_provided' => true,
        ]));

        $this->receiveDocuments($incomplete, 3);
        $this->receiveDocuments($complete, 6);

        $this->getJson('/api/applicant-applications?documents_status=no_documents&per_page=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $noDocuments->id)
            ->assertJsonPath('data.0.documents_status', 'no_documents')
            ->assertJsonPath('data.0.documents_count', 0)
            ->assertJsonPath('data.0.required_documents_count', 6)
            ->assertJsonPath('data.0.documents_missing_count', 6)
            ->assertJsonPath('data.0.documents_complete', false);

        $this->getJson('/api/applicant-applications?documents_status=incomplete&per_page=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $incomplete->id)
            ->assertJsonPath('data.0.documents_status', 'incomplete')
            ->assertJsonPath('data.0.documents_count', 3)
            ->assertJsonPath('data.0.required_documents_count', 6)
            ->assertJsonPath('data.0.documents_missing_count', 3)
            ->assertJsonPath('data.0.documents_complete', false);

        $this->getJson('/api/applicant-applications?documents_status=complete&per_page=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $complete->id)
            ->assertJsonPath('data.0.documents_status', 'complete')
            ->assertJsonPath('data.0.documents_count', 6)
            ->assertJsonPath('data.0.required_documents_count', 6)
            ->assertJsonPath('data.0.documents_missing_count', 0)
            ->assertJsonPath('data.0.documents_complete', true);

        $stats = $this->getJson('/api/admissions/stats')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.no_documents', 1)
            ->assertJsonPath('data.incomplete', 1)
            ->assertJsonPath('data.complete', 1)
            ->assertJsonPath('data.documents_provided', 3)
            ->json('data');

        $this->assertSame($stats['total'], $stats['no_documents'] + $stats['incomplete'] + $stats['complete']);
    }

    public function test_it_updates_applicant_application_document_status(): void
    {
        $program = $this->createProgram();
        $application = ApplicantApplication::create($this->payload($program));

        $response = $this->patchJson("/api/applicant-applications/{$application->id}/documents/passport", [
            'is_received' => true,
            'received_at' => '2026-06-26',
            'number' => '4500 123456',
            'comment' => 'Копия паспорта принята.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.documents_received_count', 1)
            ->assertJsonPath('data.documents.0.type', 'passport')
            ->assertJsonPath('data.documents.0.is_received', true);

        $this->assertDatabaseHas('applicant_application_documents', [
            'applicant_application_id' => $application->id,
            'type' => 'passport',
            'is_received' => true,
            'number' => '4500 123456',
        ]);
        $this->assertDatabaseHas('applicant_application_events', [
            'applicant_application_id' => $application->id,
            'type' => 'document_updated',
        ]);
    }

    public function test_it_enrolls_applicant_application_to_student(): void
    {
        $program = $this->createProgram();
        $group = $this->createGroup($program);
        $application = ApplicantApplication::create($this->payload($program, ['status' => 'accepted']));
        $this->receiveAllDocuments($application);

        $response = $this->postJson("/api/applicant-applications/{$application->id}/enroll", [
            'group_id' => $group->id,
            'enrollment_date' => '2026-09-01',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.application.status', 'enrolled')
            ->assertJsonPath('data.application.events.0.type', 'enrolled')
            ->assertJsonPath('data.student.last_name', 'Анохин')
            ->assertJsonPath('data.student.group.id', $group->id);

        $this->assertDatabaseHas('students', [
            'group_id' => $group->id,
            'last_name' => 'Анохин',
            'email' => 'applicant@example.test',
            'status' => 'active',
            'enrollment_date' => '2026-09-01 00:00:00',
        ]);
        $this->assertDatabaseHas('applicant_applications', [
            'id' => $application->id,
            'status' => 'enrolled',
        ]);
        $this->assertDatabaseHas('applicant_application_events', [
            'applicant_application_id' => $application->id,
            'type' => 'enrolled',
        ]);
    }

    public function test_it_rejects_enrollment_when_required_documents_are_missing(): void
    {
        $program = $this->createProgram();
        $group = $this->createGroup($program);
        $application = ApplicantApplication::create($this->payload($program, ['status' => 'accepted']));

        $this->postJson("/api/applicant-applications/{$application->id}/enroll", [
            'group_id' => $group->id,
            'enrollment_date' => '2026-09-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents']);

        $this->assertDatabaseMissing('students', ['email' => 'applicant@example.test']);
        $this->assertDatabaseHas('applicant_applications', [
            'id' => $application->id,
            'status' => 'accepted',
        ]);
    }

    public function test_it_returns_json_validation_errors_for_api_requests_without_json_accept_header(): void
    {
        $program = $this->createProgram();
        $group = $this->createGroup($program);
        $application = ApplicantApplication::create($this->payload($program, ['status' => 'accepted']));

        $this->withHeader('Accept', 'text/html')
            ->post("/api/applicant-applications/{$application->id}/enroll", [
                'group_id' => $group->id,
                'enrollment_date' => '2026-09-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents']);

        $this->assertDatabaseMissing('students', ['email' => 'applicant@example.test']);
    }

    public function test_it_rejects_enrollment_when_student_email_already_exists(): void
    {
        $program = $this->createProgram();
        $group = $this->createGroup($program);
        $application = ApplicantApplication::create($this->payload($program, ['status' => 'accepted']));
        $this->receiveAllDocuments($application);
        Student::create([
            'group_id' => $group->id,
            'last_name' => 'Анохин',
            'first_name' => 'Дмитрий',
            'email' => 'applicant@example.test',
            'status' => 'active',
            'enrollment_date' => '2026-09-01',
        ]);

        $this->postJson("/api/applicant-applications/{$application->id}/enroll", [
            'group_id' => $group->id,
            'enrollment_date' => '2026-09-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseHas('applicant_applications', [
            'id' => $application->id,
            'status' => 'accepted',
        ]);
    }

    public function test_it_deletes_applicant_application(): void
    {
        $program = $this->createProgram();
        $application = ApplicantApplication::create($this->payload($program));

        $this->deleteJson("/api/applicant-applications/{$application->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('applicant_applications', ['id' => $application->id]);
    }

    public function test_it_validates_applicant_application_status(): void
    {
        $program = $this->createProgram();

        $this->postJson('/api/applicant-applications', $this->payload($program, ['status' => 'unknown']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    private function createProgram(): EducationProgram
    {
        $specialty = Specialty::create([
            'code' => '53.02.04',
            'name' => 'Вокальное искусство',
            'education_level' => 'Среднее профессиональное образование',
        ]);

        return EducationProgram::create([
            'specialty_id' => $specialty->id,
            'name' => 'ППССЗ Вокальное искусство',
            'year_start' => 2026,
            'study_form' => 'Очная',
            'study_years' => 3.8,
        ]);
    }

    private function createGroup(EducationProgram $program): Group
    {
        return Group::create([
            'name' => 'ВИ-101',
            'specialty' => 'Вокальное искусство',
            'education_program_id' => $program->id,
            'course' => 1,
            'year_start' => 2026,
        ]);
    }

    private function receiveDocuments(ApplicantApplication $application, int $count): void
    {
        foreach (array_slice([
            'passport' => 'Паспорт',
            'education_document' => 'Документ об образовании',
            'snils' => 'СНИЛС',
            'consent' => 'Согласие на обработку персональных данных',
            'photo' => 'Фотография',
            'medical_certificate' => 'Медицинская справка',
        ], 0, $count) as $type => $title) {
            ApplicantApplicationDocument::create([
                'applicant_application_id' => $application->id,
                'type' => $type,
                'title' => $title,
                'is_received' => true,
                'received_at' => now(),
            ]);
        }
    }

    private function receiveAllDocuments(ApplicantApplication $application): void
    {
        $this->patchJson("/api/applicant-applications/{$application->id}/documents/passport", ['is_received' => true]);
        $this->patchJson("/api/applicant-applications/{$application->id}/documents/education_document", ['is_received' => true]);
        $this->patchJson("/api/applicant-applications/{$application->id}/documents/snils", ['is_received' => true]);
        $this->patchJson("/api/applicant-applications/{$application->id}/documents/consent", ['is_received' => true]);
        $this->patchJson("/api/applicant-applications/{$application->id}/documents/photo", ['is_received' => true]);
        $this->patchJson("/api/applicant-applications/{$application->id}/documents/medical_certificate", ['is_received' => true]);
    }

    private function payload(EducationProgram $program, array $overrides = []): array
    {
        return [
            ...[
                'education_program_id' => $program->id,
                'last_name' => 'Анохин',
                'first_name' => 'Дмитрий',
                'middle_name' => 'Алексеевич',
                'birth_date' => '2010-03-14',
                'phone' => '+79990000010',
                'email' => 'applicant@example.test',
                'education_base' => 'after_9',
                'status' => 'new',
                'submitted_at' => '2026-06-25',
                'comment' => 'Первичное обращение.',
            ],
            ...$overrides,
        ];
    }
}
