<?php

namespace Tests\Feature;

use App\Models\EducationProgram;
use App\Models\Specialty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_public_specialties_without_token(): void
    {
        Specialty::create([
            'code' => '53.02.04',
            'name' => 'Вокальное искусство',
            'education_level' => 'Среднее профессиональное образование',
            'qualification' => 'Артист-вокалист, преподаватель',
        ]);

        $this->getJson('/api/public/specialties')
            ->assertOk()
            ->assertJsonPath('data.0.code', '53.02.04');
    }

    public function test_it_lists_public_education_programs_without_token(): void
    {
        $specialty = Specialty::create([
            'code' => '53.02.04',
            'name' => 'Вокальное искусство',
            'education_level' => 'Среднее профессиональное образование',
        ]);

        EducationProgram::create([
            'specialty_id' => $specialty->id,
            'name' => 'ППССЗ Вокальное искусство',
            'year_start' => 2026,
            'study_form' => 'Очная',
            'study_years' => 4,
        ]);

        $this->getJson('/api/public/education-programs')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'ППССЗ Вокальное искусство')
            ->assertJsonPath('data.0.specialty.code', '53.02.04');
    }
}
