<?php

namespace Tests\Feature;

use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TeacherCsvApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_exports_teachers_to_csv(): void
    {
        Teacher::create([
            'last_name' => 'Смирнова',
            'first_name' => 'Елена',
            'middle_name' => 'Викторовна',
            'email' => 'smirnova@example.test',
            'position' => 'Преподаватель',
            'department' => 'Теоретическое отделение',
            'is_active' => true,
        ]);

        $response = $this->get('/api/teachers/export');

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        // Колонки выгрузки — подписи шаблона импорта, а не машинные имена полей:
        // файл отдают как заготовку и грузят обратно. Прежний заголовок
        // `last_name` тут закреплялся именно потому, что симметрии не было.
        $this->assertStringContainsString('Фамилия', $content);
        $this->assertStringContainsString('Смирнова', $content);
        $this->assertStringContainsString('Теоретическое отделение', $content);
    }

    public function test_it_imports_teachers_from_csv(): void
    {
        $existing = Teacher::create([
            'last_name' => 'Смирнова',
            'first_name' => 'Елена',
            'email' => 'smirnova@example.test',
            'is_active' => true,
        ]);

        $csv = implode("\n", [
            'id;last_name;first_name;middle_name;phone;email;position;department;is_active',
            "{$existing->id};Смирнова;Елена;Викторовна;79990000001;smirnova@example.test;Преподаватель;Теоретическое отделение;да",
            ';Рябцев;Андрей;Александрович;79990000002;ryabtsev@example.test;Концертмейстер;Вокальное отделение;нет',
        ]);

        $file = UploadedFile::fake()->createWithContent('teachers.csv', $csv);

        $response = $this->post('/api/teachers/import', [
            'file' => $file,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.errors', []);

        $this->assertDatabaseHas('teachers', [
            'id' => $existing->id,
            'middle_name' => 'Викторовна',
            'department' => 'Теоретическое отделение',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('teachers', [
            'last_name' => 'Рябцев',
            'email' => 'ryabtsev@example.test',
            'is_active' => false,
        ]);
    }

    public function test_it_returns_line_errors_for_invalid_teacher_rows(): void
    {
        $csv = implode("\n", [
            'id;last_name;first_name;middle_name;phone;email;position;department;is_active',
            ';Смирнова;;;;bad-email;;;может быть',
        ]);

        $file = UploadedFile::fake()->createWithContent('teachers.csv', $csv);

        $response = $this->post('/api/teachers/import', [
            'file' => $file,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.errors.0.line', 2);

        $this->assertDatabaseMissing('teachers', [
            'last_name' => 'Смирнова',
        ]);
    }
}
