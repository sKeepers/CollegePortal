<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Permission;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TeachingLoadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_it_creates_lists_updates_and_deletes_teaching_load(): void
    {
        $teacher = $this->createTeacher();

        $response = $this->postJson('/api/teaching-loads', [
            'academic_year' => '2026/2027',
            'teacher_id' => $teacher->id,
            'status' => 'active',
            'description' => 'Основная нагрузка преподавателя.',
        ]);

        $response->assertCreated()->assertJsonPath('data.teacher.last_name', 'Смирнова');
        $loadId = $response->json('data.id');

        $this->getJson('/api/teaching-loads?academic_year=2026/2027&teacher_id='.$teacher->id)
            ->assertOk()
            ->assertJsonPath('data.0.academic_year', '2026/2027');

        $this->patchJson("/api/teaching-loads/{$loadId}", ['status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->deleteJson("/api/teaching-loads/{$loadId}")->assertNoContent();
        $this->assertDatabaseMissing('teaching_loads', ['id' => $loadId]);
    }

    public function test_it_adds_and_removes_teaching_load_item(): void
    {
        $teacher = $this->createTeacher();
        $subject = Subject::create(['name' => 'Сольфеджио', 'code' => 'OP.01']);
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $load = TeachingLoad::create(['academic_year' => '2026/2027', 'teacher_id' => $teacher->id, 'status' => 'draft']);

        $response = $this->postJson("/api/teaching-loads/{$load->id}/items", [
            'subject_id' => $subject->id,
            'group_id' => $group->id,
            'semester' => 1,
            'hours_total' => 144,
            'load_type' => 'Аудиторная',
        ]);

        $response->assertCreated()->assertJsonPath('data.subject.name', 'Сольфеджио')->assertJsonPath('data.group.name', 'ИСП-101');
        $itemId = $response->json('data.id');

        $this->deleteJson("/api/teaching-load-items/{$itemId}")->assertNoContent();
        $this->assertDatabaseMissing('teaching_load_items', ['id' => $itemId]);
    }

    public function test_it_exports_and_imports_teaching_loads_csv(): void
    {
        $teacher = $this->createTeacher();
        $subject = Subject::create(['name' => 'Сольфеджио', 'code' => 'OP.01']);
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $load = TeachingLoad::create(['academic_year' => '2026/2027', 'teacher_id' => $teacher->id, 'status' => 'active']);
        $load->items()->create(['subject_id' => $subject->id, 'group_id' => $group->id, 'semester' => 1, 'hours_total' => 144, 'load_type' => 'Аудиторная']);

        $export = $this->get('/api/teaching-loads/export');
        $export->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Смирнова Елена Викторовна', $export->streamedContent());

        $csv = implode("\n", [
            'id;academic_year;teacher_id;teacher;status;description;subject_id;subject_code;subject_name;group_id;group_name;semester;hours_total;load_type;sort_order',
            ";2027/2028;{$teacher->id};;draft;Следующий год;;OP.01;;{$group->id};;2;108;Консультации;1",
        ]);
        $response = $this->post('/api/teaching-loads/import', [
            'file' => UploadedFile::fake()->createWithContent('teaching-loads.csv', $csv),
        ]);

        $response->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.itemsCreated', 1);
        $this->assertDatabaseHas('teaching_loads', ['academic_year' => '2027/2028', 'teacher_id' => $teacher->id]);
        $this->assertDatabaseHas('teaching_load_items', ['subject_id' => $subject->id, 'group_id' => $group->id, 'semester' => 2, 'hours_total' => 108]);
    }

    public function test_teacher_can_only_view_own_teaching_loads(): void
    {
        $teacherUser = $this->createTeacherUser();
        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'last_name' => 'Петрова',
            'first_name' => 'Анна',
            'is_active' => true,
        ]);
        $otherTeacher = $this->createTeacher();
        $ownLoad = TeachingLoad::create(['academic_year' => '2026/2027', 'teacher_id' => $teacher->id, 'status' => 'active']);
        $assignedLoad = TeachingLoad::create(['academic_year' => '2026/2027', 'teacher_id' => $otherTeacher->id, 'status' => 'active']);
        $otherLoad = TeachingLoad::create(['academic_year' => '2026/2027', 'teacher_id' => $otherTeacher->id, 'status' => 'active']);
        $subject = Subject::create(['name' => 'Гармония', 'code' => 'OP.02']);
        $group = Group::create(['name' => 'ИСП-102', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $assignedLoad->items()->create(['subject_id' => $subject->id, 'group_id' => $group->id, 'teacher_id' => $teacher->id, 'semester' => 1, 'hours_total' => 36, 'load_type' => 'Аудиторная']);

        $response = $this->withApiAuth($teacherUser)->getJson('/api/teaching-loads');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$ownLoad->id, $assignedLoad->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains($otherLoad->id, collect($response->json('data'))->pluck('id')->all());
        $this->withApiAuth($teacherUser)->postJson('/api/teaching-loads', ['academic_year' => '2026/2027', 'teacher_id' => $teacher->id])->assertForbidden();
    }

    public function test_self_scope_without_teacher_profile_is_forbidden(): void
    {
        $user = $this->createTeacherUser();

        $this->withApiAuth($user)->getJson('/api/teaching-loads')->assertForbidden();
    }

    private function createTeacherUser(): \App\Models\User
    {
        $user = $this->createApiUser(roleCode: 'teacher');
        $permission = Permission::create([
            'module' => 'Legacy',
            'code' => 'view_own_data',
            'name' => 'Просмотр личных данных',
            'active' => true,
        ]);
        $user->role->permissions()->attach($permission);

        return $user;
    }

    private function createTeacher(): Teacher
    {
        return Teacher::create([
            'last_name' => 'Смирнова',
            'first_name' => 'Елена',
            'middle_name' => 'Викторовна',
            'is_active' => true,
        ]);
    }
}
