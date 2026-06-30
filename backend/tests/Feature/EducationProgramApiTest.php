<?php

namespace Tests\Feature;

use App\Models\EducationProgram;
use App\Models\Specialty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class EducationProgramApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_lists_education_programs(): void
    {
        $specialty = $this->createSpecialty();
        EducationProgram::create([
            'specialty_id' => $specialty->id,
            'name' => 'ППССЗ Вокальное искусство',
            'year_start' => 2026,
            'study_form' => 'Очная',
        ]);

        $this->getJson('/api/education-programs')
            ->assertOk()
            ->assertJsonPath('data.0.specialty.code', '53.02.04');
    }

    public function test_it_creates_education_program(): void
    {
        $specialty = $this->createSpecialty();

        $response = $this->postJson('/api/education-programs', [
            'specialty_id' => $specialty->id,
            'name' => 'ППССЗ Вокальное искусство',
            'year_start' => 2026,
            'study_form' => 'Очная',
            'study_years' => 4,
            'is_active' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'ППССЗ Вокальное искусство')
            ->assertJsonPath('data.specialty.id', $specialty->id);

        $this->assertDatabaseHas('education_programs', ['name' => 'ППССЗ Вокальное искусство']);
    }

    public function test_it_updates_education_program(): void
    {
        $specialty = $this->createSpecialty();
        $program = EducationProgram::create([
            'specialty_id' => $specialty->id,
            'name' => 'ППССЗ Вокальное искусство',
            'year_start' => 2026,
            'study_form' => 'Очная',
        ]);

        $this->patchJson("/api/education-programs/{$program->id}", [
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_it_deletes_education_program(): void
    {
        $specialty = $this->createSpecialty();
        $program = EducationProgram::create([
            'specialty_id' => $specialty->id,
            'name' => 'ППССЗ Вокальное искусство',
            'year_start' => 2026,
            'study_form' => 'Очная',
        ]);

        $this->deleteJson("/api/education-programs/{$program->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('education_programs', ['id' => $program->id]);
    }

    public function test_it_exports_education_programs_to_csv(): void
    {
        $specialty = $this->createSpecialty();
        EducationProgram::create([
            'specialty_id' => $specialty->id,
            'name' => 'ППССЗ Вокальное искусство',
            'year_start' => 2026,
            'study_form' => 'Очная',
        ]);

        $response = $this->get('/api/education-programs/export');

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('ППССЗ Вокальное искусство', $response->streamedContent());
    }

    public function test_it_imports_education_programs_from_csv(): void
    {
        $specialty = $this->createSpecialty();
        $existing = EducationProgram::create([
            'specialty_id' => $specialty->id,
            'name' => 'ППССЗ Вокальное искусство',
            'year_start' => 2026,
            'study_form' => 'Очная',
        ]);

        $csv = implode("\n", [
            'id;specialty_id;specialty_code;name;year_start;study_form;study_years;is_active;description',
            "{$existing->id};;53.02.04;ППССЗ Вокальное искусство;2026;Очная;4;да;Основная программа",
            ';;53.02.04;ППССЗ Вокальное искусство;2027;Очная;4;нет;',
        ]);

        $response = $this->post('/api/education-programs/import', [
            'file' => UploadedFile::fake()->createWithContent('education-programs.csv', $csv),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.errors', []);

        $this->assertDatabaseHas('education_programs', ['id' => $existing->id, 'description' => 'Основная программа']);
        $this->assertDatabaseHas('education_programs', ['year_start' => 2027, 'is_active' => false]);
    }

    public function test_it_returns_line_errors_for_invalid_education_program_rows(): void
    {
        $csv = implode("\n", [
            'id;specialty_id;specialty_code;name;year_start;study_form;study_years;is_active;description',
            ';;;ППССЗ Вокальное искусство;1999;Очная;abc;может быть;',
        ]);

        $response = $this->post('/api/education-programs/import', [
            'file' => UploadedFile::fake()->createWithContent('education-programs.csv', $csv),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.errors.0.line', 2);
    }

    private function createSpecialty(): Specialty
    {
        return Specialty::create([
            'code' => '53.02.04',
            'name' => 'Вокальное искусство',
            'education_level' => 'Среднее профессиональное образование',
        ]);
    }
}
