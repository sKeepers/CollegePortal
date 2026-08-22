<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\EducationProgram;
use App\Models\Specialty;
use App\Models\Student;
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

        // Курс не задаётся, он считается: чтобы группа стала второкурсной,
        // меняется год набора.
        $response = $this->patchJson("/api/groups/{$group->id}", [
            'year_start' => \App\Models\Group::academicYear() - 1,
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


    /**
     * Главное, ради чего писался отказ. До 23.08.2026 удаление группы делало
     * простой `$group->delete()`, а у `students.group_id` стоит `ON DELETE
     * CASCADE` — каскад срабатывает в PostgreSQL, мимо Eloquent, и студенты
     * исчезали физически: ни мягкого удаления, ни корзины, ни возврата.
     * Пользователь видел один вопрос «Удалить группу?».
     */
    public function test_it_refuses_to_delete_a_group_with_students(): void
    {
        $group = Group::create([
            'name' => 'V-102',
            'specialty' => 'Vocal Performance',
            'course' => 1,
            'year_start' => 2026,
        ]);

        $student = Student::create([
            'last_name' => 'Иванова',
            'first_name' => 'Мария',
            'group_id' => $group->id,
        ]);

        $this->deleteJson("/api/groups/{$group->id}")
            ->assertStatus(409)
            ->assertJsonPath('blockers.0.table', 'students')
            ->assertJsonPath('blockers.0.count', 1);

        $this->assertDatabaseHas('groups', ['id' => $group->id]);
        $this->assertDatabaseHas('students', ['id' => $student->id]);
    }

    /** Отказ обязан называть, что мешает, а не просто отказывать. */
    public function test_the_refusal_names_what_blocks_it(): void
    {
        $group = Group::create([
            'name' => 'V-103',
            'specialty' => 'Vocal Performance',
            'course' => 1,
            'year_start' => 2026,
        ]);

        Student::create(['last_name' => 'Петрова', 'first_name' => 'Анна', 'group_id' => $group->id]);
        Student::create(['last_name' => 'Сидорова', 'first_name' => 'Ольга', 'group_id' => $group->id]);

        $message = $this->deleteJson("/api/groups/{$group->id}")
            ->assertStatus(409)
            ->json('message');

        $this->assertStringContainsString('2 студентов', $message);
    }

    /** Пустую группу удалять можно и нужно — это рабочий случай. */
    public function test_an_empty_group_is_still_deleted(): void
    {
        $group = Group::create([
            'name' => 'V-104',
            'specialty' => 'Vocal Performance',
            'course' => 1,
            'year_start' => 2026,
        ]);

        $this->deleteJson("/api/groups/{$group->id}")->assertNoContent();

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
