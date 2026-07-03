<?php

namespace Tests\Feature;

use App\Models\EducationProgram;
use App\Models\Graduate;
use App\Models\Group;
use App\Models\Specialty;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class GraduateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_it_creates_lists_updates_and_deletes_graduate(): void
    {
        [$student, $group, $program, $specialty] = $this->baseEntities();

        $response = $this->postJson('/api/graduates', [
            'student_id' => $student->id,
            'group_id' => $group->id,
            'education_program_id' => $program->id,
            'specialty_id' => $specialty->id,
            'graduation_year' => 2027,
            'qualification' => 'Артист, преподаватель',
            'status' => 'ready',
        ]);

        $response->assertCreated()->assertJsonPath('data.student.last_name', 'Иванов')->assertJsonPath('data.group.name', 'ИСП-401');
        $graduateId = $response->json('data.id');

        $this->getJson('/api/graduates?graduation_year=2027&group_id='.$group->id)
            ->assertOk()
            ->assertJsonPath('data.0.qualification', 'Артист, преподаватель');

        $this->patchJson("/api/graduates/{$graduateId}", ['status' => 'issued'])
            ->assertOk()
            ->assertJsonPath('data.status', 'issued');

        $this->deleteJson("/api/graduates/{$graduateId}")->assertNoContent();
        $this->assertDatabaseMissing('graduates', ['id' => $graduateId]);
    }

    public function test_it_saves_diploma_and_supplement(): void
    {
        [$student, $group, $program, $specialty] = $this->baseEntities();
        $graduate = Graduate::create(['student_id' => $student->id, 'group_id' => $group->id, 'education_program_id' => $program->id, 'specialty_id' => $specialty->id, 'graduation_year' => 2027, 'qualification' => 'Артист', 'status' => 'ready']);

        $this->postJson("/api/graduates/{$graduate->id}/diploma", [
            'series' => 'СК',
            'number' => '000001',
            'registration_number' => '27-001',
            'issue_date' => '2027-06-30',
            'qualification' => 'Артист',
            'gia_decision' => 'Протокол ГИА №1',
            'status' => 'issued',
        ])->assertCreated()->assertJsonPath('data.status', 'issued');

        $this->postJson("/api/graduates/{$graduate->id}/supplement", [
            'series' => 'ПД',
            'number' => '000001',
            'issue_date' => '2027-06-30',
            'status' => 'ready',
        ])->assertCreated()->assertJsonPath('data.number', '000001');

        $this->assertDatabaseHas('diplomas', ['graduate_id' => $graduate->id, 'registration_number' => '27-001']);
        $this->assertDatabaseHas('diploma_supplements', ['number' => '000001']);
    }

    public function test_it_exports_and_imports_graduates_csv(): void
    {
        [$student, $group, $program, $specialty] = $this->baseEntities();
        $graduate = Graduate::create(['student_id' => $student->id, 'group_id' => $group->id, 'education_program_id' => $program->id, 'specialty_id' => $specialty->id, 'graduation_year' => 2027, 'qualification' => 'Артист', 'status' => 'ready']);
        $graduate->diploma()->create(['series' => 'СК', 'number' => '000001', 'registration_number' => '27-001', 'status' => 'issued']);

        $export = $this->get('/api/graduates/export');
        $export->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Иванов Иван Петрович', $export->streamedContent());

        $student2 = Student::create(['group_id' => $group->id, 'last_name' => 'Петров', 'first_name' => 'Петр', 'status' => 'active']);
        $csv = implode("\n", [
            'id;student_id;student;group_id;group_name;education_program_id;education_program;specialty_id;specialty;graduation_year;qualification;status;diploma_series;diploma_number;registration_number;issue_date;gia_decision;diploma_status;supplement_series;supplement_number;supplement_status;note',
            ";{$student2->id};;{$group->id};;{$program->id};;{$specialty->id};;2028;Артист;ready;СК;000002;28-002;2028-06-30;Протокол ГИА №2;issued;;;;",
        ]);

        $response = $this->post('/api/graduates/import', [
            'file' => UploadedFile::fake()->createWithContent('graduates.csv', $csv),
        ]);

        $response->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.diplomasSaved', 1);
        $this->assertDatabaseHas('graduates', ['student_id' => $student2->id, 'graduation_year' => 2028]);
        $this->assertDatabaseHas('diplomas', ['registration_number' => '28-002']);
    }

    private function baseEntities(): array
    {
        $specialty = Specialty::create(['code' => '53.02.03', 'name' => 'Инструментальное исполнительство', 'education_level' => 'СПО', 'qualification' => 'Артист, преподаватель']);
        $program = EducationProgram::create(['specialty_id' => $specialty->id, 'name' => 'Фортепиано', 'year_start' => 2023, 'study_form' => 'Очная', 'study_years' => 4, 'is_active' => true]);
        $group = Group::create(['name' => 'ИСП-401', 'specialty' => 'Инструментальное исполнительство', 'education_program_id' => $program->id, 'course' => 4, 'year_start' => 2023]);
        $student = Student::create(['group_id' => $group->id, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'middle_name' => 'Петрович', 'status' => 'active']);

        return [$student, $group, $program, $specialty];
    }
}
