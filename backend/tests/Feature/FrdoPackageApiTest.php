<?php

namespace Tests\Feature;

use App\Models\EducationProgram;
use App\Models\Graduate;
use App\Models\Group;
use App\Models\Specialty;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrdoPackageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_it_creates_validates_exports_and_archives_frdo_package(): void
    {
        $graduate = $this->createGraduateWithDiploma();

        $response = $this->postJson('/api/frdo-packages', [
            'graduation_year' => 2027,
            'education_program_id' => $graduate->education_program_id,
            'name' => 'ФРДО 2027',
        ]);

        $response->assertCreated()->assertJsonPath('data.records_count', 1)->assertJsonPath('data.status', 'draft');
        $packageId = $response->json('data.id');

        $this->postJson("/api/frdo-packages/{$packageId}/validate")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.validation_errors_count', 0);

        $this->get("/api/frdo-packages/{$packageId}/export.csv")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->getJson("/api/frdo-packages/{$packageId}/export.json")
            ->assertOk()
            ->assertJsonPath('package.id', $packageId)
            ->assertJsonPath('records.0.payload.student', 'Иванов Иван Петрович');

        $this->postJson("/api/frdo-packages/{$packageId}/mark-exported")
            ->assertOk()
            ->assertJsonPath('data.status', 'exported');

        $this->postJson("/api/frdo-packages/{$packageId}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
    }

    public function test_validation_reports_missing_required_data(): void
    {
        $graduate = $this->createGraduateWithDiploma(false);
        $packageId = $this->postJson('/api/frdo-packages', ['graduation_year' => 2027, 'education_program_id' => $graduate->education_program_id])->json('data.id');

        $this->postJson("/api/frdo-packages/{$packageId}/validate")
            ->assertOk()
            ->assertJsonPath('data.status', 'validation_failed')
            ->assertJsonPath('data.records.0.status', 'invalid');

        $this->assertDatabaseHas('frdo_validation_errors', ['frdo_package_id' => $packageId, 'field' => 'diploma_series']);
    }

    private function createGraduateWithDiploma(bool $complete = true): Graduate
    {
        $specialty = Specialty::create(['code' => '53.02.04', 'name' => 'Вокальное искусство', 'education_level' => 'СПО', 'qualification' => 'Артист-вокалист, преподаватель']);
        $program = EducationProgram::create(['specialty_id' => $specialty->id, 'name' => 'ППССЗ Вокальное искусство', 'year_start' => 2023, 'study_form' => 'Очная', 'study_years' => 4, 'is_active' => true]);
        $group = Group::create(['name' => 'ВИ-401', 'specialty' => 'Вокальное искусство', 'education_program_id' => $program->id, 'course' => 4, 'year_start' => 2023]);
        $student = Student::create(['group_id' => $group->id, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'middle_name' => 'Петрович', 'birth_date' => '2007-05-10', 'status' => 'active']);
        $graduate = Graduate::create(['student_id' => $student->id, 'group_id' => $group->id, 'education_program_id' => $program->id, 'specialty_id' => $specialty->id, 'graduation_year' => 2027, 'qualification' => 'Артист-вокалист, преподаватель', 'status' => 'ready']);
        $graduate->diploma()->create($complete
            ? ['series' => 'СК', 'number' => '000001', 'registration_number' => '27-001', 'issue_date' => '2027-06-30', 'qualification' => 'Артист-вокалист, преподаватель', 'status' => 'issued']
            : ['status' => 'draft']
        );

        return $graduate->fresh();
    }
}
