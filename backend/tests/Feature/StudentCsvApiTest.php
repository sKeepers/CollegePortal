<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class StudentCsvApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_exports_students_to_csv(): void
    {
        $group = $this->createGroup('ИСП-101');

        Student::create([
            'group_id' => $group->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'middle_name' => 'Сергеевич',
            'email' => 'ivanov@example.test',
            'status' => 'active',
            'enrollment_date' => '2026-09-01',
        ]);

        $response = $this->get('/api/students/export');

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        // Выгрузка отдаёт колонки шаблона импорта, а не технические имена полей:
        // файл нужен как заготовка для заполнения и обратной загрузки.
        $this->assertStringContainsString('Фамилия', $content);
        $this->assertStringContainsString('Специальность', $content);
        $this->assertStringContainsString('Иванов', $content);
        $this->assertStringContainsString('ИСП-101', $content);
    }

    public function test_it_imports_students_from_csv(): void
    {
        $group = $this->createGroup('ИСП-101');
        $existing = Student::create([
            'group_id' => $group->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'status' => 'active',
        ]);

        $csv = implode("\n", [
            'id;group_id;group;last_name;first_name;middle_name;birth_date;phone;email;snils;status;enrollment_date',
            "{$existing->id};{$group->id};;Иванов;Дмитрий;Сергеевич;;;ivanov@example.test;;academic_leave;2026-09-01",
            ";{$group->id};;Соколова;Анна;Павловна;;;sokolova@example.test;112-233-445 95;active;2026-09-01",
        ]);

        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $response = $this->post('/api/students/import', [
            'file' => $file,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.errors', []);

        $this->assertDatabaseHas('students', [
            'id' => $existing->id,
            'status' => 'academic_leave',
            'email' => 'ivanov@example.test',
        ]);
        $this->assertDatabaseHas('students', [
            'last_name' => 'Соколова',
            'first_name' => 'Анна',
            'email' => 'sokolova@example.test',
        ]);
    }

    public function test_it_imports_students_with_blank_group_when_only_one_group_exists(): void
    {
        $group = $this->createGroup('ИСП-101');
        $csv = implode("\n", [
            'id;group_id;group;last_name;first_name;middle_name;birth_date;phone;email;snils;status;enrollment_date',
            ";;;Анохин;Дмитрий;Алексеевич;;79990000002;student@example.test;112-233-445 95;active;01.09.2026",
            ";;;Борисова;Софья;Владимировна;;79990000003;student2@example.test;901-144-044 41;active;02.09.2026",
        ]);

        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $response = $this->post('/api/students/import', [
            'file' => $file,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.errors', []);

        $this->assertDatabaseHas('students', [
            'group_id' => $group->id,
            'last_name' => 'Анохин',
        ]);
        $this->assertDatabaseHas('students', [
            'group_id' => $group->id,
            'last_name' => 'Борисова',
        ]);

        $this->assertSame('2026-09-01', Student::where('last_name', 'Анохин')->first()->enrollment_date->toDateString());
        $this->assertSame('2026-09-02', Student::where('last_name', 'Борисова')->first()->enrollment_date->toDateString());
    }

    private function createGroup(string $name): Group
    {
        return Group::create([
            'name' => $name,
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);
    }
}
