<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\EducationProgram;
use App\Models\Group;
use App\Models\Specialty;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingLoad;
use App\Models\TeachingLoadItem;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeachingLoadGenerationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->createApiUser(roleCode: 'study'));
    }

    public function test_preview_does_not_change_database_and_apply_is_idempotent(): void
    {
        [$group] = $this->createCurriculumContext();

        $this->postJson('/api/teaching-load/generate/preview', [
            'group_id' => $group->id,
            'academic_year' => '2026/2027',
        ])->assertOk()
            ->assertJsonPath('data.found', 2)
            ->assertJsonPath('data.will_create', 2)
            ->assertJsonPath('data.unassigned_teachers', 2);

        $this->assertDatabaseCount('teaching_loads', 0);
        $this->assertDatabaseCount('teaching_load_items', 0);

        $this->postJson('/api/teaching-load/generate/apply', [
            'group_id' => $group->id,
            'academic_year' => '2026/2027',
        ])->assertOk()
            ->assertJsonPath('data.found', 2)
            ->assertJsonPath('data.will_create', 2);

        $this->assertDatabaseCount('teaching_loads', 1);
        $this->assertDatabaseCount('teaching_load_items', 2);
        $this->assertDatabaseHas('audit_logs', ['module' => 'Teaching Load', 'action' => 'teaching_load_generated_from_curriculum']);

        $this->postJson('/api/teaching-load/generate/apply', [
            'group_id' => $group->id,
            'academic_year' => '2026/2027',
        ])->assertOk()
            ->assertJsonPath('data.will_create', 0)
            ->assertJsonPath('data.will_update', 2);

        $this->assertDatabaseCount('teaching_loads', 1);
        $this->assertDatabaseCount('teaching_load_items', 2);
    }

    public function test_generation_keeps_manual_items_and_reports_conflict(): void
    {
        [$group, $curriculum, $subjects] = $this->createCurriculumContext();
        $teacher = $this->createTeacher();
        $manualLoad = TeachingLoad::create(['academic_year' => '2026/2027', 'teacher_id' => $teacher->id, 'status' => 'draft']);
        $manualLoad->items()->create([
            'subject_id' => $subjects[0]->id,
            'group_id' => $group->id,
            'semester' => 1,
            'hours_total' => 12,
            'planned_hours' => 12,
            'assigned_hours' => 12,
            'unassigned_hours' => 0,
            'load_type' => 'Аудиторная',
            'source' => 'manual',
            'assignment_status' => 'assigned',
        ]);

        $this->postJson('/api/teaching-load/generate/preview', [
            'group_id' => $group->id,
            'academic_year' => '2026/2027',
        ])->assertOk()->assertJsonPath('data.conflicts', 1);

        $this->postJson('/api/teaching-load/generate/apply', [
            'group_id' => $group->id,
            'academic_year' => '2026/2027',
        ])->assertOk();

        $this->assertDatabaseHas('teaching_load_items', ['source' => 'manual', 'hours_total' => 12]);
        $this->assertDatabaseCount('teaching_load_items', 3);
    }

    public function test_assign_teacher_bulk_assign_and_coverage_calculations(): void
    {
        [$group] = $this->createCurriculumContext();
        $teacher = $this->createTeacher();
        $this->postJson('/api/teaching-load/generate/apply', ['group_id' => $group->id, 'academic_year' => '2026/2027'])->assertOk();
        $load = TeachingLoad::firstOrFail();
        $items = TeachingLoadItem::query()->orderBy('id')->get();

        $this->postJson("/api/teaching-load/items/{$items[0]->id}/assign-teacher", [
            'teacher_id' => $teacher->id,
            'assigned_hours' => 150,
        ])->assertOk()
            ->assertJsonPath('data.teacher_id', $teacher->id)
            ->assertJsonPath('data.assignment_status', 'overassigned')
            ->assertJsonPath('data.overassigned_hours', 30);

        $this->postJson('/api/teaching-load/items/bulk-assign-teacher', [
            'ids' => [$items[1]->id],
            'teacher_id' => $teacher->id,
        ])->assertOk()->assertJsonPath('data.changed', 1);

        $this->getJson("/api/teaching-load/{$load->id}/coverage")
            ->assertOk()
            ->assertJsonPath('data.planned_hours', 192)
            ->assertJsonPath('data.assigned_hours', 222)
            ->assertJsonPath('data.overassigned_hours', 30);

        $this->assertDatabaseHas('audit_logs', ['module' => 'Teaching Load', 'action' => 'teaching_load_item_assigned']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'Teaching Load', 'action' => 'teaching_load_items_bulk_assigned']);
    }

    public function test_group_without_curriculum_returns_preview_error_without_write(): void
    {
        $program = $this->createProgram();
        $group = Group::create(['name' => 'NO-CUR-101', 'specialty' => 'Искусство', 'education_program_id' => $program->id, 'course' => 1, 'year_start' => 2026]);

        $this->postJson('/api/teaching-load/generate/preview', [
            'group_id' => $group->id,
            'academic_year' => '2026/2027',
        ])->assertOk()
            ->assertJsonPath('data.errors', 1)
            ->assertJsonPath('data.found', 0);

        $this->assertDatabaseCount('teaching_loads', 0);
    }

    public function test_generation_requires_permission(): void
    {
        [$group] = $this->createCurriculumContext();
        $this->withApiAuth($this->createApiUser(roleCode: 'teacher'));

        $this->postJson('/api/teaching-load/generate/apply', [
            'group_id' => $group->id,
            'academic_year' => '2026/2027',
        ])->assertForbidden();
    }

    private function createCurriculumContext(): array
    {
        $program = $this->createProgram();
        $subjectA = Subject::create(['name' => 'Сольфеджио', 'code' => 'OP.01']);
        $subjectB = Subject::create(['name' => 'История искусств', 'code' => 'OGSE.01']);
        $curriculum = Curriculum::create(['education_program_id' => $program->id, 'name' => 'Учебный план', 'year_start' => 2026, 'status' => 'active']);
        $curriculum->subjects()->create(['semester' => 1, 'subject_id' => $subjectA->id, 'lecture_hours' => 40, 'practice_hours' => 80, 'total_hours' => 120, 'sequence' => 1]);
        $curriculum->subjects()->create(['semester' => 2, 'subject_id' => $subjectB->id, 'lecture_hours' => 24, 'practice_hours' => 48, 'total_hours' => 72, 'sequence' => 2]);
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Искусство', 'education_program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'course' => 1, 'year_start' => 2026]);

        return [$group, $curriculum, [$subjectA, $subjectB]];
    }

    private function createProgram(): EducationProgram
    {
        $specialty = Specialty::create(['code' => '53.02.03', 'name' => 'Инструментальное исполнительство', 'education_level' => 'СПО']);
        return EducationProgram::create(['specialty_id' => $specialty->id, 'name' => 'ППССЗ Инструментальное исполнительство', 'year_start' => 2026, 'study_form' => 'Очная', 'is_active' => true]);
    }

    private function createTeacher(): Teacher
    {
        return Teacher::create(['last_name' => 'Петров', 'first_name' => 'Петр', 'middle_name' => 'Петрович', 'email' => 'teacher-load@example.test', 'is_active' => true]);
    }
}
