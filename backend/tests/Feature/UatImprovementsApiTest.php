<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\CurriculumItem;
use App\Models\EducationProgram;
use App\Models\Specialty;
use App\Models\Group;
use App\Models\Role;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
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

        $this->postJson('/api/admin/demo-data/create')
            ->assertOk()
            ->assertJsonPath('message', 'Демо-данные созданы или обновлены.');

        $this->assertDatabaseHas('students', ['email' => 'student@college-portal.local']);
        $this->assertDatabaseHas('teachers', ['email' => 'teacher@college-portal.local']);
        $this->assertTrue(Hash::check('test1234', User::where('email', 'admin@college-portal.local')->firstOrFail()->password));
        $this->assertTrue(Hash::check('test1234', User::where('email', 'teacher@college-portal.local')->firstOrFail()->password));
        $this->assertTrue(Hash::check('test1234', User::where('email', 'student@college-portal.local')->firstOrFail()->password));

        $this->postJson('/api/admin/demo-data/clear')
            ->assertOk()
            ->assertJsonPath('message', 'Демо-данные очищены. Администратор не удаляется.');

        $this->assertDatabaseMissing('students', ['email' => 'student@college-portal.local']);
        $this->assertDatabaseMissing('teachers', ['email' => 'teacher@college-portal.local']);
    }



    public function test_demo_data_clear_skips_referenced_demo_subjects(): void
    {
        Role::query()->firstOrCreate(['code' => 'teacher'], ['name' => 'Teacher']);
        Role::query()->firstOrCreate(['code' => 'student'], ['name' => 'Student']);

        $this->postJson('/api/admin/demo-data/create')->assertOk();

        $subject = Subject::query()->where('code', 'MUS-101')->firstOrFail();
        $program = EducationProgram::query()->firstOrFail();
        $curriculum = Curriculum::create([
            'education_program_id' => $program->id,
            'name' => 'Тестовый учебный план',
            'year_start' => 2026,
            'status' => 'draft',
        ]);
        CurriculumItem::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'course' => 1,
            'semester' => 1,
            'hours_total' => 72,
        ]);

        $this->postJson('/api/admin/demo-data/clear')
            ->assertOk()
            ->assertJsonPath('data.skipped.subjects', 1);

        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
    }

    public function test_demo_data_clear_is_forbidden_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->postJson('/api/admin/demo-data/clear')
            ->assertForbidden()
            ->assertJsonPath('message', 'Очистка демо-данных запрещена в production.');
    }
}
