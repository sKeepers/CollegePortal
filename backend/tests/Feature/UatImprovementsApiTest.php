<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Role;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UatImprovementsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_generates_subject_code_when_code_is_empty(): void
    {
        $response = $this->postJson('/api/subjects', [
            'name' => 'История искусств',
            'code' => '',
            'department' => 'Общеобразовательное отделение',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'История искусств');

        $this->assertNotEmpty($response->json('data.code'));
        $this->assertDatabaseHas('subjects', ['name' => 'История искусств']);
    }



    public function test_it_generates_specialty_code_when_code_is_empty(): void
    {
        $response = $this->postJson('/api/specialties', [
            'code' => '',
            'name' => 'Декоративно-прикладное искусство',
            'education_level' => 'Среднее профессиональное образование',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Декоративно-прикладное искусство');

        $this->assertNotEmpty($response->json('data.code'));
    }

    public function test_it_generates_group_name_when_name_is_empty(): void
    {
        $response = $this->postJson('/api/groups', [
            'name' => '',
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);

        $response->assertCreated();

        $this->assertNotEmpty($response->json('data.name'));
    }

    public function test_it_uploads_and_removes_student_photo(): void
    {
        Storage::fake('public');
        $group = Group::create([
            'name' => 'ИСП-101',
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);
        $student = Student::create([
            'group_id' => $group->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'status' => 'active',
        ]);

        $imagePath = storage_path('framework/testing/student-photo.png');
        File::ensureDirectoryExists(dirname($imagePath));
        file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));

        $response = $this->post('/api/person-photos/students/'.$student->id, [
            'photo' => new UploadedFile($imagePath, 'student.png', 'image/png', null, true),
        ]);

        $response->assertOk();
        $path = $response->json('data.photo_path');
        $this->assertNotEmpty($path);
        Storage::disk('public')->assertExists($path);

        $this->deleteJson('/api/person-photos/students/'.$student->id)->assertNoContent();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'photo_path' => null]);
    }



    public function test_it_rejects_invalid_photo_file(): void
    {
        $group = Group::create([
            'name' => 'ИСП-102',
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);
        $student = Student::create([
            'group_id' => $group->id,
            'last_name' => 'Петров',
            'first_name' => 'Илья',
            'status' => 'active',
        ]);

        $this->post('/api/person-photos/students/'.$student->id, [
            'photo' => UploadedFile::fake()->create('not-image.txt', 16, 'text/plain'),
        ])->assertUnprocessable()->assertJsonValidationErrors('photo');
    }

    public function test_demo_data_management_creates_and_clears_demo_records(): void
    {
        Role::query()->firstOrCreate(['code' => 'teacher'], ['name' => 'Teacher']);
        Role::query()->firstOrCreate(['code' => 'student'], ['name' => 'Student']);

        $response = $this->postJson('/api/admin/demo-data/create')
            ->assertOk()
            ->assertJsonPath('message', 'Демонстрационная база создана или обновлена.');

        $this->assertSame(600, $response->json('data.students'));
        $this->assertSame(70, $response->json('data.teachers'));
        $this->assertSame(30, $response->json('data.groups'));
        $this->assertTrue($response->json('data.demo_mode'));

        $this->assertDatabaseHas('students', [
            'email' => 'student0001@demo.college-portal.local',
            'snils' => null,
        ]);
        $this->assertDatabaseHas('teachers', [
            'email' => 'teacher001@demo.college-portal.local',
            'snils' => null,
        ]);

        $clearResponse = $this->postJson('/api/admin/demo-data/clear')
            ->assertOk()
            ->assertJsonPath('message', 'Демонстрационные данные очищены.');

        $this->assertSame(0, $clearResponse->json('data.summary.students'));
        $this->assertSame(0, $clearResponse->json('data.summary.teachers'));
        $this->assertSame(0, $clearResponse->json('data.summary.groups'));
        $this->assertFalse($clearResponse->json('data.summary.demo_mode'));
        $this->assertDatabaseMissing('students', ['email' => 'student0001@demo.college-portal.local']);
        $this->assertDatabaseMissing('teachers', ['email' => 'teacher001@demo.college-portal.local']);
    }



    public function test_demo_data_reset_removes_generated_demo_curriculum_records(): void
    {
        Role::query()->firstOrCreate(['code' => 'teacher'], ['name' => 'Teacher']);
        Role::query()->firstOrCreate(['code' => 'student'], ['name' => 'Student']);

        $this->postJson('/api/admin/demo-data/create')->assertOk();

        $this->assertDatabaseHas('subjects', ['code' => 'DEMO-SUBJ-01']);
        $this->assertDatabaseHas('curricula', ['code' => 'DEMO-CUR-01']);
        $this->assertDatabaseHas('curriculum_subjects', ['semester' => 1]);

        $this->postJson('/api/admin/demo-data/clear')
            ->assertOk()
            ->assertJsonPath('data.summary.subjects', 0)
            ->assertJsonPath('data.summary.curricula', 0);

        $this->assertDatabaseMissing('subjects', ['code' => 'DEMO-SUBJ-01']);
        $this->assertDatabaseMissing('curricula', ['code' => 'DEMO-CUR-01']);
    }

    public function test_demo_data_clear_is_forbidden_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->postJson('/api/admin/demo-data/clear')
            ->assertForbidden()
            ->assertJsonPath('message', 'Очистка демо-данных запрещена в production.');
    }
}
