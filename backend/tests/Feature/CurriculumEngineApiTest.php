<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\EducationProgram;
use App\Models\Group;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Specialty;
use App\Models\Subject;
use App\Services\CurriculumEngineService;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurriculumEngineApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->createApiUser(roleCode: 'study'));
    }

    public function test_it_manages_curriculum_subjects_and_calculates_summary_with_audit(): void
    {
        $program = $this->createProgram();
        $subject = Subject::create(['name' => 'Сольфеджио', 'code' => 'OP.01']);
        $curriculum = Curriculum::create([
            'education_program_id' => $program->id,
            'name' => 'Учебный план 2026',
            'qualification' => 'Артист, преподаватель',
            'year_start' => 2026,
            'status' => 'active',
        ]);
        $exam = ReferenceItem::query()->whereHas('catalog', fn ($query) => $query->where('code', 'control_types'))->where('code', 'exam')->firstOrFail();

        $response = $this->postJson("/api/curricula/{$curriculum->id}/subjects", [
            'semester' => 1,
            'subject_id' => $subject->id,
            'lecture_hours' => 20,
            'practice_hours' => 40,
            'laboratory_hours' => 10,
            'independent_hours' => 30,
            'control_type_id' => $exam->id,
            'sequence' => 5,
            'is_optional' => false,
            'competencies' => ['ОК-1'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.total_hours', 100)
            ->assertJsonPath('data.control_type', 'exam')
            ->assertJsonPath('data.subject.name', 'Сольфеджио');
        $subjectId = $response->json('data.id');

        $this->getJson("/api/curricula/{$curriculum->id}/summary")
            ->assertOk()
            ->assertJsonPath('data.subjects_count', 1)
            ->assertJsonPath('data.total_hours', 100)
            ->assertJsonPath('data.exams_count', 1);

        $this->getJson("/api/curricula/{$curriculum->id}/semesters")
            ->assertOk()
            ->assertJsonPath('data.0.semester', 1)
            ->assertJsonPath('data.0.total_hours', 100);

        $this->putJson("/api/curriculum-subjects/{$subjectId}", [
            'practice_hours' => 50,
        ])->assertOk()->assertJsonPath('data.total_hours', 110);

        $this->assertDatabaseHas('audit_logs', ['module' => 'Curricula', 'action' => 'curriculum_subject_created']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'Curricula', 'action' => 'curriculum_subject_updated']);

        $this->deleteJson("/api/curriculum-subjects/{$subjectId}")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['module' => 'Curricula', 'action' => 'curriculum_subject_deleted']);
    }

    public function test_curriculum_engine_returns_subjects_for_group_curriculum(): void
    {
        $program = $this->createProgram();
        $subject = Subject::create(['name' => 'История искусств', 'code' => 'OGSE.01']);
        $curriculum = Curriculum::create(['education_program_id' => $program->id, 'name' => 'Учебный план', 'year_start' => 2026, 'status' => 'active']);
        $curriculum->subjects()->create(['semester' => 2, 'subject_id' => $subject->id, 'lecture_hours' => 24, 'total_hours' => 24, 'sequence' => 1]);
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Искусство', 'education_program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'course' => 1, 'year_start' => 2026]);

        $subjects = app(CurriculumEngineService::class)->subjectsForGroup($group, 2);

        $this->assertCount(1, $subjects);
        $this->assertSame('История искусств', $subjects->first()->subject->name);
    }

    public function test_curriculum_subject_permissions_are_required(): void
    {
        $this->withApiAuth($this->createApiUser(roleCode: 'teacher'));
        $program = $this->createProgram();
        $curriculum = Curriculum::create(['education_program_id' => $program->id, 'name' => 'Учебный план', 'year_start' => 2026, 'status' => 'active']);

        $this->getJson("/api/curricula/{$curriculum->id}/subjects")->assertForbidden();
    }

    public function test_reference_data_contains_control_types(): void
    {
        $catalog = ReferenceCatalog::query()->where('code', 'control_types')->firstOrFail();

        $this->assertDatabaseHas('reference_items', ['catalog_id' => $catalog->id, 'code' => 'exam']);
        $this->assertDatabaseHas('reference_items', ['catalog_id' => $catalog->id, 'code' => 'gia']);
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
