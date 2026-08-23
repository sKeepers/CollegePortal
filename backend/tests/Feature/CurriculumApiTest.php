<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\EducationProgram;
use App\Models\Specialty;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CurriculumApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_it_creates_lists_updates_and_deletes_curriculum(): void
    {
        $program = $this->createProgram();

        $response = $this->postJson('/api/curricula', [
            'education_program_id' => $program->id,
            'name' => 'Учебный план ИСП 2026',
            'year_start' => 2026,
            'status' => 'active',
            'description' => 'Базовый план приема 2026 года.',
        ]);

        $response->assertCreated()->assertJsonPath('data.education_program.name', 'ППССЗ Инструментальное исполнительство');
        $curriculumId = $response->json('data.id');

        $this->getJson('/api/curricula?year_start=2026&specialty_id='.$program->specialty_id)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Учебный план ИСП 2026');

        $this->patchJson("/api/curricula/{$curriculumId}", ['status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->deleteJson("/api/curricula/{$curriculumId}")->assertNoContent();
        $this->assertDatabaseMissing('curricula', ['id' => $curriculumId]);
    }

    public function test_it_adds_and_removes_curriculum_item(): void
    {
        $program = $this->createProgram();
        $subject = Subject::create(['name' => 'Сольфеджио', 'code' => 'OP.01']);
        $curriculum = Curriculum::create(['education_program_id' => $program->id, 'name' => 'Учебный план', 'year_start' => 2026, 'status' => 'draft']);

        $response = $this->postJson("/api/curricula/{$curriculum->id}/items", [
            'subject_id' => $subject->id,
            'course' => 1,
            'semester' => 1,
            'hours_total' => 144,
            'control_form' => 'Экзамен',
        ]);

        $response->assertCreated()->assertJsonPath('data.subject.name', 'Сольфеджио');
        $itemId = $response->json('data.id');

        $this->deleteJson("/api/curriculum-items/{$itemId}")->assertNoContent();
        $this->assertDatabaseMissing('curriculum_items', ['id' => $itemId]);
    }

    public function test_it_exports_and_imports_curricula_csv(): void
    {
        $program = $this->createProgram();
        $subject = Subject::create(['name' => 'Сольфеджио', 'code' => 'OP.01']);
        $curriculum = Curriculum::create(['education_program_id' => $program->id, 'name' => 'Учебный план ИСП 2026', 'year_start' => 2026, 'status' => 'active']);
        $curriculum->items()->create(['subject_id' => $subject->id, 'course' => 1, 'semester' => 1, 'hours_total' => 144, 'control_form' => 'Экзамен']);

        $export = $this->get('/api/curricula/export');
        $export->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Учебный план ИСП 2026', $export->streamedContent());

        $csv = implode("\n", [
            'id;education_program_id;program_name;specialty;year_start;name;status;description;subject_id;subject_code;subject_name;course;semester;hours_total;control_form;sort_order',
            ";{$program->id};;Инструментальное исполнительство;2027;Учебный план ИСП 2027;draft;Новый план;;OP.01;;1;2;108;Зачет;10",
        ]);
        $response = $this->post('/api/curricula/import', [
            'file' => UploadedFile::fake()->createWithContent('curricula.csv', $csv),
        ]);

        $response->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.itemsCreated', 1);
        $this->assertDatabaseHas('curricula', ['name' => 'Учебный план ИСП 2027', 'year_start' => 2027]);
        $this->assertDatabaseHas('curriculum_subjects', ['subject_id' => $subject->id, 'semester' => 2, 'total_hours' => 108]);
    }

    private function createProgram(): EducationProgram
    {
        $specialty = Specialty::create([
            'code' => '53.02.03',
            'name' => 'Инструментальное исполнительство',
            'education_level' => 'Среднее профессиональное образование',
        ]);

        return EducationProgram::create([
            'specialty_id' => $specialty->id,
            'name' => 'ППССЗ Инструментальное исполнительство',
            'year_start' => 2026,
            'study_form' => 'Очная',
            'is_active' => true,
        ]);
    }
}
