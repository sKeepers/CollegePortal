<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Person;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Выгрузка сотрудников. Смысл её не в самом файле, а в том, что он грузится
 * обратно тем же импортом: иначе выгрузка перед очисткой базы бесполезна.
 */
class EmployeeExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_export_uses_the_import_template_headers(): void
    {
        $this->employee('Альгашова', 'Мария', '0042');

        $response = $this->get('/api/employees/export');
        $response->assertOk();

        $csv = $response->streamedContent();

        // BOM, иначе Excel открывает кириллицу набором символов.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        // fputcsv берет в кавычки поля с пробелами — это корректный CSV.
        $this->assertStringContainsString('"Табельный номер";Фамилия;Имя;Отчество;"Дата рождения";СНИЛС;Email;Телефон;Подразделение;Должность;"Дата приема";Статус;Занятость;Ставка;Преподаватель;"Рабочий график";"Создать учетную запись"', $csv);
        $this->assertStringContainsString('0042;Альгашова;Мария', $csv);
        $this->assertStringContainsString('"Учебная часть";Методист', $csv);
    }

    public function test_exported_file_is_accepted_by_the_employee_import(): void
    {
        $this->employee('Альгашова', 'Мария', '0042');
        $this->employee('Иванов', 'Дмитрий', '0043');

        $csv = $this->get('/api/employees/export')->assertOk()->streamedContent();
        $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

        // Импорт узнаёт каждую колонку выгрузки — значит файл грузится обратно.
        $this->post('/api/admin/import/preview', ['data_type' => 'employees', 'file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.mapping.employee_number', 'Табельный номер')
            ->assertJsonPath('data.mapping.last_name', 'Фамилия')
            ->assertJsonPath('data.mapping.department', 'Подразделение')
            ->assertJsonPath('data.mapping.position', 'Должность')
            ->assertJsonPath('data.mapping.hired_at', 'Дата приема')
            ->assertJsonPath('data.mapping.employment_type', 'Занятость')
            ->assertJsonPath('data.mapping.is_teacher', 'Преподаватель')
            ->assertJsonPath('data.mapping.work_schedule_code', 'Рабочий график');
    }

    public function test_export_leaves_the_account_column_empty(): void
    {
        $this->employee('Иванов', 'Дмитрий', '0043');

        $csv = $this->get('/api/employees/export')->assertOk()->streamedContent();
        $row = collect(explode("\n", trim($csv)))->last();

        // Последняя колонка — «Создать учетную запись». Обратная загрузка не
        // должна переоформлять учётки, поэтому она пустая.
        $this->assertStringEndsWith(';', rtrim($row, "\r"));
    }

    private function employee(string $lastName, string $firstName, string $number): Employee
    {
        $person = Person::create([
            'last_name' => $lastName,
            'first_name' => $firstName,
            'phone' => '+7900123456'.substr($number, -1),
            'status' => 'active',
        ]);

        return Employee::create([
            'person_id' => $person->id,
            'employee_number' => $number,
            'status' => 'active',
            'employment_type' => 'full_time',
            'work_schedule_code' => 'weekday_0900_1800',
            'hired_at' => '2026-01-15',
            'workload_rate' => 1,
            'is_teacher' => false,
            'primary_department_id' => Department::firstOrCreate(['name' => 'Учебная часть'], ['code' => 'STUDY'])->id,
            'primary_position_id' => Position::firstOrCreate(['name' => 'Методист'], ['code' => 'METHOD'])->id,
        ]);
    }
}
