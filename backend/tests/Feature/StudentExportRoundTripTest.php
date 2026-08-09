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

    /**
     * Загрузок студентов две: «Универсальный импорт» и кнопка в самом реестре.
     * Один и тот же файл обязан давать одинаковый результат, иначе половина
     * контингента окажется с неполными карточками в зависимости от того,
     * какой кнопкой его загрузили.
     */
    public function test_registry_import_creates_person_level_documents_like_universal_import(): void
    {
        $group = Group::firstOrCreate(
            ['name' => 'ИСП-101'],
            ['specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026],
        );

        $csv = "\xEF\xBB\xBFФамилия;Имя;Группа;Дата рождения;Серия паспорта;Номер паспорта;Дата выдачи паспорта;Серия документа об образовании;Номер документа об образовании;Учебное заведение;Год окончания\n"
            ."Зорин;Артём;ИСП-101;12.05.2009;0712;345678;20.05.2025;АБ;123456;МБОУ СОШ № 1;2026\n"
            ."Белкин;Иван;ИСП-101;13.06.2009;;;;;;;\n";

        $this->post('/api/students/import', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csv),
        ])->assertOk()->assertJsonPath('data.created', 2);

        $withDocuments = Student::query()->where('last_name', 'Зорин')->firstOrFail();
        $withoutDocuments = Student::query()->where('last_name', 'Белкин')->firstOrFail();

        $this->assertNotNull($withDocuments->person_id, 'Импорт обязан связать студента с человеком.');
        $this->assertDatabaseHas('admission_identity_documents', [
            'person_id' => $withDocuments->person_id,
            'number' => '345678',
            'applicant_id' => null,
        ]);
        $this->assertDatabaseHas('admission_education_documents', [
            'person_id' => $withDocuments->person_id,
            'number' => '123456',
            'graduation_year' => 2026,
        ]);

        // Строка без документов — не ошибка: студент заведён с неполной карточкой.
        $this->assertNotNull($withoutDocuments->person_id);
        $this->assertDatabaseMissing('admission_education_documents', ['person_id' => $withoutDocuments->person_id]);
        $this->assertSame($group->id, $withoutDocuments->group_id);
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
