<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\EducationProgram;
use App\Models\Specialty;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_lists_groups(): void
    {
        Group::create([
            'name' => 'M-101',
            'specialty' => 'Instrumental Performance',
            'course' => 1,
            'year_start' => 2026,
        ]);

        $response = $this->getJson('/api/groups');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.name', 'M-101');
    }

    public function test_it_creates_group(): void
    {
        $teacher = Teacher::create([
            'last_name' => 'Petrov',
            'first_name' => 'Alexey',
            'email' => 'teacher@example.test',
        ]);
        $program = $this->createEducationProgram();

        $response = $this->postJson('/api/groups', [
            'name' => 'D-201',
            'specialty' => 'Design',
            'education_program_id' => $program->id,
            'course' => 2,
            'year_start' => 2025,
            'curator_id' => $teacher->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'D-201')
            ->assertJsonPath('data.education_program.id', $program->id)
            ->assertJsonPath('data.curator_id', $teacher->id);

        $this->assertDatabaseHas('groups', ['name' => 'D-201']);
    }

    public function test_it_updates_group(): void
    {
        $group = Group::create([
            'name' => 'A-101',
            'specialty' => 'Acting',
            'course' => 1,
            'year_start' => 2026,
        ]);

        $response = $this->patchJson("/api/groups/{$group->id}", [
            'course' => 2,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.course', 2);
    }

    public function test_it_deletes_group(): void
    {
        $group = Group::create([
            'name' => 'V-101',
            'specialty' => 'Vocal Performance',
            'course' => 1,
            'year_start' => 2026,
        ]);

        $this->deleteJson("/api/groups/{$group->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('groups', ['id' => $group->id]);
    }

    private function createEducationProgram(): EducationProgram
    {
        $specialty = Specialty::create([
            'code' => '54.02.01',
            'name' => 'Дизайн',
            'education_level' => 'Среднее профессиональное образование',
        ]);

        return EducationProgram::create([
            'specialty_id' => $specialty->id,
            'name' => 'ППССЗ Дизайн',
            'year_start' => 2025,
            'study_form' => 'Очная',
        ]);
    }
}
