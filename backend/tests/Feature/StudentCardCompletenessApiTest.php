<?php

namespace Tests\Feature;

use App\Models\Admissions\IdentityDocument;
use App\Models\Group;
use App\Models\Person;
use App\Models\Student;
use Database\Seeders\AdmissionReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CompletesStudentCard;
use Tests\TestCase;

class StudentCardCompletenessApiTest extends TestCase
{
    use CompletesStudentCard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(AdmissionReferenceSeeder::class);
        $this->withApiAuth();
    }

    public function test_student_without_admission_application_can_have_identity_document(): void
    {
        $student = $this->createStudentThroughApi(['snils' => '112-233-445 95']);

        $response = $this->postJson("/api/students/{$student['id']}/identity-documents", [
            'document_type_id' => $this->referenceItemId('admission_identity_document_types', 'russian_passport'),
            'series' => '0712',
            'number' => '345678',
            'issue_date' => '2025-03-01',
            'issued_by' => 'Отдел УФМС',
            'is_primary' => true,
        ]);

        $response->assertCreated()->assertJsonPath('data.applicant_id', null);

        $document = IdentityDocument::query()->findOrFail($response->json('data.id'));
        $this->assertNull($document->applicant_id, 'Паспорт студента заводится без заявления приёмной комиссии.');
        $this->assertSame(Student::findOrFail($student['id'])->person_id, $document->person_id);
    }

    public function test_card_completeness_lists_missing_parts(): void
    {
        $student = $this->createStudentThroughApi(['snils' => null]);

        $this->getJson("/api/students/{$student['id']}/card-completeness")
            ->assertOk()
            ->assertJsonPath('data.complete', false)
            ->assertJsonPath('data.identity_document.status', 'missing')
            ->assertJsonPath('data.education_document.status', 'missing')
            ->assertJsonPath('data.snils.status', 'missing');

        $this->postJson("/api/students/{$student['id']}/identity-documents", [
            'document_type_id' => $this->referenceItemId('admission_identity_document_types', 'russian_passport'),
            'series' => '0712',
            'number' => '345678',
        ])->assertCreated();

        $this->postJson("/api/students/{$student['id']}/education-documents", [
            'document_type_id' => $this->referenceItemId('admission_education_document_types', 'basic_general_certificate'),
            'series' => 'АБ',
            'number' => '123456',
        ])->assertCreated();

        $this->getJson("/api/students/{$student['id']}/card-completeness")
            ->assertOk()
            ->assertJsonPath('data.identity_document.status', 'present')
            ->assertJsonPath('data.education_document.status', 'present')
            ->assertJsonPath('data.complete', false)
            ->assertJsonPath('data.snils.status', 'missing');
    }

    public function test_student_saves_without_snils_and_warns_about_probable_duplicate(): void
    {
        $group = $this->createGroup();

        Person::query()->create([
            'last_name' => 'Северов',
            'first_name' => 'Илья',
            'middle_name' => 'Петрович',
            'birth_date' => '2008-09-01',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/students', [
            'group_id' => $group->id,
            'last_name' => 'Северов',
            'first_name' => 'Илья',
            'middle_name' => 'Петрович',
            'birth_date' => '2008-09-01',
            'status' => 'active',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('warnings.snils_missing', true)
            ->assertJsonPath('data.card_completeness.complete', false);

        $this->assertCount(1, $response->json('warnings.duplicate_candidates'));
        $this->assertNotNull(
            Student::query()->findOrFail($response->json('data.id'))->person_id,
            'Даже без СНИЛС студент связан с собственным человеком, иначе документы прикрепить некуда.',
        );
    }

    public function test_registry_can_filter_incomplete_cards(): void
    {
        $incomplete = $this->createStudentThroughApi(['snils' => null]);

        $this->getJson('/api/students?completeness=incomplete')
            ->assertOk()
            ->assertJsonPath('data.0.id', $incomplete['id'])
            ->assertJsonPath('data.0.card_completeness.complete', false);

        $this->getJson('/api/students/card-completeness/summary')
            ->assertOk()
            ->assertJsonPath('data.incomplete', 1)
            ->assertJsonPath('data.missing_snils', 1);
    }

    public function test_enrollment_order_is_blocked_until_card_is_complete(): void
    {
        $student = $this->createStudentThroughApi(['snils' => null]);

        $this->patchJson("/api/students/{$student['id']}", ['enrollment_order_number' => '91'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enrollment_order_number');

        $this->completeStudentCard(Student::query()->findOrFail($student['id']));

        $this->patchJson("/api/students/{$student['id']}", ['enrollment_order_number' => '91'])
            ->assertOk()
            ->assertJsonPath('data.enrollment_order_number', '91')
            ->assertJsonPath('data.card_completeness.complete', true);
    }

    public function test_study_records_role_reaches_new_routes_and_student_role_does_not(): void
    {
        $student = $this->createStudentThroughApi();

        $registrar = $this->createApiUser(roleCode: 'study_records');
        $this->withApiAuth($registrar)
            ->getJson('/api/students/card-completeness/summary')
            ->assertOk();
        $this->withApiAuth($registrar)
            ->getJson("/api/students/{$student['id']}/card-completeness")
            ->assertOk();
        $this->withApiAuth($registrar)
            ->getJson("/api/students/{$student['id']}/documents")
            ->assertOk();

        $this->withApiAuth($this->createApiUser(roleCode: 'student'))
            ->getJson('/api/students/card-completeness/summary')
            ->assertForbidden();
    }

    public function test_student_import_accepts_passport_and_education_document_but_does_not_require_them(): void
    {
        $this->createGroup();

        $csv = "Фамилия;Имя;Группа;Дата рождения;Серия паспорта;Номер паспорта;Дата выдачи паспорта;Серия документа об образовании;Номер документа об образовании;Год окончания\n"
            ."Зорин;Артём;ФО-101;12.05.2009;0712;345678;20.05.2025;АБ;123456;2026\n"
            ."Белкин;Иван;ФО-101;13.06.2009;;;;;;\n";

        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $jobId = $this->post('/api/admin/import/preview', ['data_type' => 'students', 'file' => $file])
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/admin/import/{$jobId}/confirm", [
            'mode' => 'create',
            'mapping' => [
                'last_name' => 'Фамилия',
                'first_name' => 'Имя',
                'group_name' => 'Группа',
                'birth_date' => 'Дата рождения',
                'passport_series' => 'Серия паспорта',
                'passport_number' => 'Номер паспорта',
                'passport_issue_date' => 'Дата выдачи паспорта',
                'education_document_series' => 'Серия документа об образовании',
                'education_document_number' => 'Номер документа об образовании',
                'education_graduation_year' => 'Год окончания',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.created_count', 2)
            ->assertJsonPath('data.error_count', 0);

        $withDocuments = Student::query()->where('last_name', 'Зорин')->firstOrFail();
        $withoutDocuments = Student::query()->where('last_name', 'Белкин')->firstOrFail();

        $this->assertDatabaseHas('admission_identity_documents', [
            'person_id' => $withDocuments->person_id,
            'number' => '345678',
            'applicant_id' => null,
        ]);
        $this->assertDatabaseHas('admission_education_documents', [
            'person_id' => $withDocuments->person_id,
            'number' => '123456',
        ]);
        $this->assertDatabaseMissing('admission_identity_documents', ['person_id' => $withoutDocuments->person_id]);
    }

    /** @return array<string, mixed> */
    private function createStudentThroughApi(array $overrides = []): array
    {
        $group = $this->createGroup();

        $response = $this->postJson('/api/students', array_merge([
            'group_id' => $group->id,
            'last_name' => 'Ветров',
            'first_name' => 'Пётр',
            'birth_date' => '2008-01-02',
            'status' => 'active',
        ], $overrides));

        $response->assertCreated();

        return $response->json('data');
    }

    private function createGroup(): Group
    {
        return Group::query()->firstOrCreate(['name' => 'ФО-101'], [
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);
    }

    private function referenceItemId(string $catalogCode, string $itemCode): int
    {
        return (int) \App\Models\ReferenceItem::query()
            ->where('code', $itemCode)
            ->whereIn('catalog_id', \App\Models\ReferenceCatalog::query()->where('code', $catalogCode)->select('id'))
            ->value('id');
    }
}
