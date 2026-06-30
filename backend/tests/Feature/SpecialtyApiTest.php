<?php

namespace Tests\Feature;

use App\Models\Specialty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SpecialtyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_lists_specialties(): void
    {
        Specialty::create([
            'code' => '53.02.04',
            'name' => 'Вокальное искусство',
            'education_level' => 'Среднее профессиональное образование',
        ]);

        $this->getJson('/api/specialties?search=53.02.04')
            ->assertOk()
            ->assertJsonPath('data.0.code', '53.02.04');
    }

    public function test_it_creates_specialty(): void
    {
        $response = $this->postJson('/api/specialties', [
            'code' => '53.02.04',
            'name' => 'Вокальное искусство',
            'education_level' => 'Среднее профессиональное образование',
            'qualification' => 'Артист-вокалист, преподаватель',
            'normative_study_years' => 4,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Вокальное искусство');

        $this->assertDatabaseHas('specialties', ['code' => '53.02.04']);
    }

    public function test_it_updates_specialty(): void
    {
        $specialty = Specialty::create([
            'code' => '53.02.04',
            'name' => 'Вокальное искусство',
            'education_level' => 'Среднее профессиональное образование',
        ]);

        $this->patchJson("/api/specialties/{$specialty->id}", [
            'qualification' => 'Артист-вокалист',
        ])
            ->assertOk()
            ->assertJsonPath('data.qualification', 'Артист-вокалист');
    }

    public function test_it_deletes_specialty(): void
    {
        $specialty = Specialty::create([
            'code' => '54.02.01',
            'name' => 'Дизайн',
            'education_level' => 'Среднее профессиональное образование',
        ]);

        $this->deleteJson("/api/specialties/{$specialty->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('specialties', ['id' => $specialty->id]);
    }

    public function test_it_exports_specialties_to_csv(): void
    {
        Specialty::create([
            'code' => '53.02.04',
            'name' => 'Вокальное искусство',
            'education_level' => 'Среднее профессиональное образование',
        ]);

        $response = $this->get('/api/specialties/export');

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('53.02.04', $response->streamedContent());
    }

    public function test_it_imports_specialties_from_csv(): void
    {
        $existing = Specialty::create([
            'code' => '53.02.04',
            'name' => 'Вокальное искусство',
            'education_level' => 'Среднее профессиональное образование',
        ]);

        $csv = implode("\n", [
            'id;code;name;education_level;qualification;normative_study_years;description',
            "{$existing->id};53.02.04;Вокальное искусство;Среднее профессиональное образование;Артист-вокалист;4;",
            ';54.02.01;Дизайн;Среднее профессиональное образование;Дизайнер;3.5;',
        ]);

        $response = $this->post('/api/specialties/import', [
            'file' => UploadedFile::fake()->createWithContent('specialties.csv', $csv),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.errors', []);

        $this->assertDatabaseHas('specialties', ['code' => '54.02.01']);
        $this->assertDatabaseHas('specialties', ['id' => $existing->id, 'qualification' => 'Артист-вокалист']);
    }

    public function test_it_returns_line_errors_for_invalid_specialty_rows(): void
    {
        $csv = implode("\n", [
            'id;code;name;education_level;qualification;normative_study_years;description',
            ';53.02.04;;Среднее профессиональное образование;;abc;',
        ]);

        $response = $this->post('/api/specialties/import', [
            'file' => UploadedFile::fake()->createWithContent('specialties.csv', $csv),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.errors.0.line', 2);
    }
}
