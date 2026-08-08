<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Выгрузка студентов нужна как заготовка: её дополняют в Excel и загружают
 * обратно. Значит колонки обязаны совпадать с шаблоном импорта, а специальность
 * должна быть в файле — она хранится у группы, и прежний экспорт её терял.
 */
class StudentExportRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_export_carries_the_specialty_from_the_group(): void
    {
        $this->student();

        $csv = $this->get('/api/students/export')->assertOk()->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Специальность', $csv);
        $this->assertStringContainsString('"Инструментальное исполнительство"', $csv);
        $this->assertStringContainsString('Альгашова;Мария', $csv);
    }

    public function test_export_columns_are_the_import_template_columns(): void
    {
        $this->student();

        $csv = $this->get('/api/students/export')->assertOk()->streamedContent();
        $header = strstr($csv, "\n", true);

        // Прежний экспорт отдавал технические имена и поля паспорта, которых
        // импорт не понимает: обратная загрузка их молча отбрасывала.
        $this->assertStringNotContainsString('last_name', (string) $header);
        $this->assertStringNotContainsString('passport_series', (string) $header);
    }

    public function test_exported_file_is_accepted_by_the_student_import(): void
    {
        $this->student();
        $this->student('Иванов', 'Дмитрий');

        $csv = $this->get('/api/students/export')->assertOk()->streamedContent();
        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $this->post('/api/admin/import/preview', ['data_type' => 'students', 'file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.mapping.last_name', 'Фамилия')
            ->assertJsonPath('data.mapping.group_name', 'Группа')
            ->assertJsonPath('data.mapping.specialty', 'Специальность')
            ->assertJsonPath('data.mapping.education_form', 'Форма обучения')
            ->assertJsonPath('data.mapping.enrollment_order_number', 'Приказ о зачислении')
            ->assertJsonPath('data.mapping.snils', 'СНИЛС');
    }

    private function student(string $lastName = 'Альгашова', string $firstName = 'Мария'): Student
    {
        $group = Group::firstOrCreate(
            ['name' => 'ИСП-101'],
            ['specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026],
        );

        return Student::create([
            'group_id' => $group->id,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'course' => 1,
            'education_form' => 'Очная',
            'status' => 'active',
        ]);
    }
}
