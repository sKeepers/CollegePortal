<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\EducationProgram;
use App\Models\Specialty;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class GroupCsvApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_exports_groups_to_csv(): void
    {
        $teacher = Teacher::create([
            'last_name' => 'Смирнова',
            'first_name' => 'Елена',
            'middle_name' => 'Викторовна',
            'email' => 'teacher@example.test',
        ]);

        Group::create([
            'name' => 'ВИ-03',
            'specialty' => 'Вокальное искусство',
            'education_program_id' => $this->createEducationProgram()->id,
            'course' => 3,
            'year_start' => 2024,
            'curator_id' => $teacher->id,
        ]);

        $response = $this->get('/api/groups/export');

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('name', $content);
        $this->assertStringContainsString('ВИ-03', $content);
        $this->assertStringContainsString('ППССЗ Вокальное искусство', $content);
        $this->assertStringContainsString('Смирнова Елена Викторовна', $content);
    }

    public function test_it_imports_groups_from_csv(): void
    {
        $teacher = Teacher::create([
            'last_name' => 'Рябцев',
            'first_name' => 'Андрей',
            'middle_name' => 'Александрович',
            'email' => 'ryabtsev@example.test',
        ]);

        $existing = Group::create([
            'name' => 'ВИ-03',
            'specialty' => 'Вокальное искусство',
            'course' => 2,
            'year_start' => 2024,
        ]);
        $program = $this->createEducationProgram();

        $csv = implode("\n", [
            'id;name;specialty;education_program_id;education_program;course;year_start;curator_id;curator',
            "{$existing->id};ВИ-03;Вокальное искусство;;;3;2024;;Рябцев Андрей Александрович",
            ";ДИ-01;Дизайн;{$program->id};;1;2026;;;",
        ]);

        $file = UploadedFile::fake()->createWithContent('groups.csv', $csv);

        $response = $this->post('/api/groups/import', [
            'file' => $file,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.errors', []);

        $this->assertDatabaseHas('groups', [
            'id' => $existing->id,
            'course' => 3,
            'curator_id' => $teacher->id,
        ]);
        $this->assertDatabaseHas('groups', [
            'name' => 'ДИ-01',
            'specialty' => 'Дизайн',
            'education_program_id' => $program->id,
            'course' => 1,
            'year_start' => 2026,
        ]);
    }

    public function test_it_returns_line_errors_for_invalid_group_rows(): void
    {
        $csv = implode("\n", [
            'id;name;specialty;education_program_id;education_program;course;year_start;curator_id;curator',
            ';ВИ-03;;;;9;1999;;;',
        ]);

        $file = UploadedFile::fake()->createWithContent('groups.csv', $csv);

        $response = $this->post('/api/groups/import', [
            'file' => $file,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.errors.0.line', 2);

        $this->assertDatabaseMissing('groups', [
            'name' => 'ВИ-03',
        ]);
    }

    private function createEducationProgram(): EducationProgram
    {
        $specialty = Specialty::firstOrCreate(
            ['code' => '53.02.04'],
            [
                'name' => 'Вокальное искусство',
                'education_level' => 'Среднее профессиональное образование',
            ]
        );

        return EducationProgram::firstOrCreate([
            'specialty_id' => $specialty->id,
            'name' => 'ППССЗ Вокальное искусство',
            'year_start' => 2026,
            'study_form' => 'Очная',
        ]);
    }
}
