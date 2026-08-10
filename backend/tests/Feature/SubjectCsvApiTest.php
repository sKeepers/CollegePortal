<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SubjectCsvApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_exports_subjects_to_csv(): void
    {
        $teacher = Teacher::create([
            'last_name' => 'Смирнова',
            'first_name' => 'Елена',
            'middle_name' => 'Викторовна',
        ]);
        $subject = Subject::create([
            'name' => 'Сольфеджио',
            'code' => 'OP.01',
            'department' => 'Теоретическое отделение',
        ]);
        $subject->teachers()->sync([$teacher->id]);

        $response = $this->get('/api/subjects/export');

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        // Выгрузка идёт колонками шаблона импорта, а не машинными именами полей:
        // файл отдают как заготовку, и он обязан приниматься там, откуда его
        // взяли. Проверка обратной загрузки — в SubjectExportRoundTripTest.
        $this->assertStringContainsString('Дисциплина', $content);
        $this->assertStringContainsString('Преподаватели', $content);
        $this->assertStringContainsString('Сольфеджио', $content);
        $this->assertStringContainsString('Смирнова Елена Викторовна', $content);
    }

    public function test_it_imports_subjects_from_csv(): void
    {
        $teacher = Teacher::create([
            'last_name' => 'Смирнова',
            'first_name' => 'Елена',
            'middle_name' => 'Викторовна',
        ]);
        $existing = Subject::create([
            'name' => 'Сольфеджио',
            'code' => 'OP.01',
        ]);

        $csv = implode("\n", [
            'id;name;code;department;description;teacher_ids;teachers',
            "{$existing->id};Сольфеджио;OP.01;Теоретическое отделение;Базовая дисциплина;;Смирнова Елена Викторовна",
            ';Музыкальная информатика;OP.02;Теоретическое отделение;;;',
        ]);

        $file = UploadedFile::fake()->createWithContent('subjects.csv', $csv);

        $response = $this->post('/api/subjects/import', [
            'file' => $file,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.errors', []);

        $this->assertDatabaseHas('subjects', [
            'id' => $existing->id,
            'department' => 'Теоретическое отделение',
            'description' => 'Базовая дисциплина',
        ]);
        $this->assertDatabaseHas('subjects', [
            'name' => 'Музыкальная информатика',
            'code' => 'OP.02',
        ]);
        $this->assertDatabaseHas('subject_teacher', [
            'subject_id' => $existing->id,
            'teacher_id' => $teacher->id,
        ]);
    }

    public function test_it_returns_line_errors_for_invalid_subject_rows(): void
    {
        $csv = implode("\n", [
            'id;name;code;department;description;teacher_ids;teachers',
            ';;OP.01;;;;Несуществующий Преподаватель',
        ]);

        $file = UploadedFile::fake()->createWithContent('subjects.csv', $csv);

        $response = $this->post('/api/subjects/import', [
            'file' => $file,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.errors.0.line', 2);

        $this->assertDatabaseMissing('subjects', [
            'code' => 'OP.01',
        ]);
    }
}
