<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Curriculum;
use App\Models\Department;
use App\Models\EducationProgram;
use App\Models\Employee;
use App\Models\Group;
use App\Models\ImportJob;
use App\Models\Position;
use App\Models\ScheduleLesson;
use App\Models\Specialty;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class UniversalImportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_returns_import_config(): void
    {
        $response = $this->getJson('/api/admin/import/config')
            ->assertOk()
            ->assertJsonPath('data.types.0.value', 'students')
            ->assertJsonPath('data.formats.1', 'xlsx');

        $types = collect($response->json('data.types'))->pluck('value')->all();
        $this->assertContains('curricula', $types);
        $this->assertContains('teaching-load', $types);
        $this->assertContains('schedule', $types);
    }


    public function test_it_downloads_csv_template_with_russian_headers(): void
    {
        $response = $this->get('/api/admin/import/templates/students.csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->getContent();
        $this->assertStringContainsString('Фамилия;Имя;Отчество;Группа', $content);
        $this->assertStringContainsString('Иванов;Дмитрий;Сергеевич;ИСП-101', $content);
    }

    public function test_it_downloads_employee_excel_template_with_russian_headers_and_example(): void
    {
        $response = $this->get('/api/admin/import/templates/employees.xlsx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = storage_path('framework/testing/employees-template.xlsx');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $response->getContent());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray();

        $this->assertSame(['Табельный номер', 'Фамилия', 'Имя', 'Отчество', 'Email', 'Телефон', 'Подразделение', 'Должность', 'Дата приема', 'Статус', 'Занятость', 'Ставка', 'Преподаватель'], $rows[0]);
        $this->assertSame('Примерова', $rows[1][1]);
        $this->assertSame('employee@example.test', $rows[1][4]);
    }

    public function test_it_imports_employee_xlsx_with_generated_number_and_references(): void
    {
        Department::create(['code' => 'study', 'name' => 'Учебная часть']);
        Position::create(['code' => 'methodist', 'name' => 'Методист']);
        $file = $this->xlsxFile('employees.xlsx', [
            ['Табельный номер', 'Фамилия', 'Имя', 'Отчество', 'Email', 'Телефон', 'Подразделение', 'Должность', 'Дата приема', 'Статус', 'Занятость', 'Ставка', 'Преподаватель'],
            ['', 'Тестова', 'Анна', 'Игоревна', 'employee-import@example.test', '+70000000001', 'study', 'methodist', '01.09.2026', 'Активен', 'Полная занятость', '0,5', 'Да'],
        ]);

        $jobId = $this->post('/api/admin/import/preview', ['data_type' => 'employees', 'file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.mapping.employment_type', 'Занятость')
            ->assertJsonPath('data.mapping.is_teacher', 'Преподаватель')
            ->json('data.id');

        $this->postJson("/api/admin/import/{$jobId}/validate", [
            'mode' => 'skip_duplicates',
            'mapping' => $this->employeeTemplateMapping(),
        ])->assertOk()->assertJsonPath('data.status', 'validated');

        $this->postJson("/api/admin/import/{$jobId}/confirm", [
            'mode' => 'skip_duplicates',
            'mapping' => $this->employeeTemplateMapping(),
        ])->assertOk()->assertJsonPath('data.created_count', 1)->assertJsonPath('data.error_count', 0);

        $employee = Employee::where('employee_number', 'EMP-000001')->firstOrFail();
        $this->assertSame('2026-09-01', $employee->hired_at->toDateString());
        $this->assertSame('0.50', $employee->workload_rate);
        $this->assertTrue($employee->is_teacher);
        $this->assertDatabaseHas('teachers', ['person_id' => $employee->person_id, 'is_active' => true]);
    }

    public function test_it_rejects_ambiguous_employee_department_match(): void
    {
        Department::create(['code' => 'office-1', 'name' => 'Общий отдел']);
        Department::create(['code' => 'office-2', 'name' => 'Общий отдел']);
        $file = $this->xlsxFile('ambiguous-employees.xlsx', [
            ['Табельный номер', 'Фамилия', 'Имя', 'Отчество', 'Email', 'Телефон', 'Подразделение', 'Должность', 'Дата приема', 'Статус', 'Занятость', 'Ставка', 'Преподаватель'],
            ['', 'Тестова', 'Анна', '', 'ambiguous-import@example.test', '', 'Общий отдел', '', '01.09.2026', 'Активен', 'Полная занятость', '1', 'Нет'],
        ]);

        $jobId = $this->post('/api/admin/import/preview', ['data_type' => 'employees', 'file' => $file])
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/admin/import/{$jobId}/validate", [
            'mode' => 'skip_duplicates',
            'mapping' => $this->employeeTemplateMapping(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'validation_failed')
            ->assertJsonPath('data.validation_errors.0.column', 'Подразделение')
            ->assertJsonPath('data.validation_errors.0.reason', 'Подразделение сопоставляется неоднозначно: используйте уникальный код.');
    }


    public function test_it_downloads_curricula_teaching_load_and_schedule_templates(): void
    {
        $this->get('/api/admin/import/templates/curricula.csv')
            ->assertOk()
            ->assertSee('Учебный план')
            ->assertSee('Форма контроля');

        $this->get('/api/admin/import/templates/teaching-load.csv')
            ->assertOk()
            ->assertSee('Учебный год')
            ->assertSee('Тип нагрузки');

        $this->get('/api/admin/import/templates/schedule.csv')
            ->assertOk()
            ->assertSee('Время начала')
            ->assertSee('Тип занятия');
    }

    public function test_it_previews_and_confirms_subject_import(): void
    {
        $file = $this->csvFile('subjects.csv', "name;code;department
История музыки;MUS-777;Музыкальное отделение
");

        $jobId = $this->post('/api/admin/import/preview', [
            'data_type' => 'subjects',
            'file' => $file,
        ])
            ->assertCreated()
            ->assertJsonPath('data.total_rows', 1)
            ->assertJsonPath('data.mapping.name', 'name')
            ->json('data.id');

        $this->postJson("/api/admin/import/{$jobId}/confirm", [
            'mode' => 'skip_duplicates',
            'mapping' => ['name' => 'name', 'code' => 'code', 'department' => 'department', 'description' => null],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.error_count', 0);

        $this->assertDatabaseHas('subjects', ['code' => 'MUS-777', 'name' => 'История музыки']);
    }

    public function test_it_validates_student_import_errors_before_confirm(): void
    {
        $file = $this->csvFile('students.csv', "last_name;first_name;group
Иванов;Дмитрий;НЕСУЩ
");

        $jobId = $this->post('/api/admin/import/preview', [
            'data_type' => 'students',
            'file' => $file,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/import/{$jobId}/validate", [
            'mode' => 'skip_duplicates',
            'mapping' => ['last_name' => 'last_name', 'first_name' => 'first_name', 'group_name' => 'group'],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'validation_failed')
            ->assertJsonPath('data.error_count', 1)
            ->assertJsonPath('data.validation_errors.0.row', 2)
            ->assertJsonPath('data.validation_errors.0.column', 'ID группы')
            ->assertJsonPath('data.validation_errors.0.value', null);
    }

    public function test_it_updates_existing_group_by_key(): void
    {
        Group::create(['name' => 'ИСП-101', 'specialty' => 'Старая специальность', 'course' => 1, 'year_start' => 2026]);
        $file = $this->csvFile('groups.csv', "name;specialty;course;year_start
ИСП-101;Инструментальное исполнительство;2;2026
");

        $jobId = $this->post('/api/admin/import/preview', [
            'data_type' => 'groups',
            'file' => $file,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/import/{$jobId}/confirm", [
            'mode' => 'update',
            'mapping' => ['name' => 'name', 'specialty' => 'specialty', 'course' => 'course', 'year_start' => 'year_start'],
        ])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1);

        $this->assertDatabaseHas('groups', ['name' => 'ИСП-101', 'course' => 2, 'specialty' => 'Инструментальное исполнительство']);
    }

    public function test_it_records_import_history(): void
    {
        ImportJob::create([
            'data_type' => 'subjects',
            'mode' => 'create',
            'status' => 'completed',
            'original_filename' => 'subjects.csv',
            'total_rows' => 2,
            'created_count' => 2,
        ]);

        $this->getJson('/api/admin/import/history?data_type=subjects')
            ->assertOk()
            ->assertJsonPath('data.0.original_filename', 'subjects.csv')
            ->assertJsonPath('data.0.created_count', 2);
    }


    public function test_it_imports_curricula_rows_through_universal_import(): void
    {
        $program = $this->createProgram();
        Subject::create(['name' => 'Специальность', 'code' => 'SPEC-001']);
        $file = $this->csvFile('curricula.csv', "Код учебного плана;Учебный план;Образовательная программа;Год начала;Статус;Дисциплина;Код дисциплины;Курс;Семестр;Часы;Форма контроля;Порядок
УП-ФО-2026;Учебный план Фортепиано 2026;{$program->name};2026;draft;Специальность;SPEC-001;1;1;144;Экзамен;10
");

        $jobId = $this->post('/api/admin/import/preview', [
            'data_type' => 'curricula',
            'file' => $file,
        ])->assertCreated()->assertJsonPath('data.total_rows', 1)->json('data.id');

        $this->postJson("/api/admin/import/{$jobId}/confirm", [
            'mode' => 'skip_duplicates',
            'mapping' => [
                'curriculum_code' => 'Код учебного плана',
                'curriculum_name' => 'Учебный план',
                'education_program_name' => 'Образовательная программа',
                'year_start' => 'Год начала',
                'status' => 'Статус',
                'subject_name' => 'Дисциплина',
                'subject_code' => 'Код дисциплины',
                'course' => 'Курс',
                'semester' => 'Семестр',
                'hours_total' => 'Часы',
                'control_form' => 'Форма контроля',
                'sort_order' => 'Порядок',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.error_count', 0);

        $curriculum = Curriculum::where('code', 'УП-ФО-2026')->firstOrFail();
        $this->assertDatabaseHas('curriculum_items', [
            'curriculum_id' => $curriculum->id,
            'course' => 1,
            'semester' => 1,
            'hours_total' => 144,
            'control_form' => 'Экзамен',
        ]);
    }

    public function test_it_imports_teaching_load_rows_through_universal_import(): void
    {
        $teacher = Teacher::create(['last_name' => 'Петрова', 'first_name' => 'Анна', 'middle_name' => 'Викторовна', 'is_active' => true]);
        Subject::create(['name' => 'Специальность', 'code' => 'SPEC-001']);
        Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $file = $this->csvFile('teaching-load.csv', "Учебный год;Преподаватель;Статус;Дисциплина;Код дисциплины;Группа;Семестр;Часы;Тип нагрузки;Порядок
2026/2027;Петрова Анна Викторовна;draft;Специальность;SPEC-001;ИСП-101;1;72;Аудиторная;10
");

        $jobId = $this->post('/api/admin/import/preview', [
            'data_type' => 'teaching-load',
            'file' => $file,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/import/{$jobId}/confirm", [
            'mode' => 'skip_duplicates',
            'mapping' => [
                'academic_year' => 'Учебный год',
                'teacher_name' => 'Преподаватель',
                'status' => 'Статус',
                'subject_name' => 'Дисциплина',
                'subject_code' => 'Код дисциплины',
                'group_name' => 'Группа',
                'semester' => 'Семестр',
                'hours_total' => 'Часы',
                'load_type' => 'Тип нагрузки',
                'sort_order' => 'Порядок',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.error_count', 0);

        $load = TeachingLoad::where('academic_year', '2026/2027')->where('teacher_id', $teacher->id)->firstOrFail();
        $this->assertDatabaseHas('teaching_load_items', [
            'teaching_load_id' => $load->id,
            'semester' => 1,
            'hours_total' => 72,
            'load_type' => 'Аудиторная',
        ]);
    }

    public function test_it_imports_schedule_rows_through_universal_import(): void
    {
        $teacher = Teacher::create(['last_name' => 'Петрова', 'first_name' => 'Анна', 'middle_name' => 'Викторовна', 'is_active' => true]);
        Subject::create(['name' => 'Специальность', 'code' => 'SPEC-001']);
        Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        Classroom::create(['number' => '201', 'building' => 'Главный корпус']);
        $file = $this->csvFile('schedule.csv', "Дата;Время начала;Время окончания;Группа;Преподаватель;Дисциплина;Код дисциплины;Аудитория;Корпус;Тип занятия;Тема
01.09.2026;09:00;10:30;ИСП-101;Петрова Анна Викторовна;Специальность;SPEC-001;201;Главный корпус;Практическое;Вводное занятие
");

        $jobId = $this->post('/api/admin/import/preview', [
            'data_type' => 'schedule',
            'file' => $file,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/import/{$jobId}/confirm", [
            'mode' => 'skip_duplicates',
            'mapping' => [
                'lesson_date' => 'Дата',
                'starts_at' => 'Время начала',
                'ends_at' => 'Время окончания',
                'group_name' => 'Группа',
                'teacher_name' => 'Преподаватель',
                'subject_name' => 'Дисциплина',
                'subject_code' => 'Код дисциплины',
                'classroom_number' => 'Аудитория',
                'classroom_building' => 'Корпус',
                'lesson_type' => 'Тип занятия',
                'topic' => 'Тема',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.error_count', 0);

        $this->assertDatabaseHas('schedule_lessons', [
            'teacher_id' => $teacher->id,
            'lesson_date' => '2026-09-01 00:00:00',
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'lesson_type' => 'Практическое',
            'topic' => 'Вводное занятие',
        ]);
    }


    private function createProgram(): EducationProgram
    {
        $specialty = Specialty::create([
            'code' => '53.02.03',
            'name' => 'Инструментальное исполнительство',
            'education_level' => 'Среднее профессиональное образование',
        ]);

        return EducationProgram::create([
            'specialty_id' => $specialty->id,
            'name' => 'Фортепиано',
            'year_start' => 2026,
            'study_form' => 'Очная',
            'is_active' => true,
        ]);
    }

    private function csvFile(string $name, string $content): UploadedFile
    {
        $path = storage_path('framework/testing/'.$name);
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function xlsxFile(string $name, array $rows): UploadedFile
    {
        $path = storage_path('framework/testing/'.$name);
        File::ensureDirectoryExists(dirname($path));
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows);
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function employeeTemplateMapping(): array
    {
        return [
            'employee_number' => 'Табельный номер',
            'last_name' => 'Фамилия',
            'first_name' => 'Имя',
            'middle_name' => 'Отчество',
            'email' => 'Email',
            'phone' => 'Телефон',
            'department' => 'Подразделение',
            'position' => 'Должность',
            'hired_at' => 'Дата приема',
            'status' => 'Статус',
            'employment_type' => 'Занятость',
            'workload_rate' => 'Ставка',
            'is_teacher' => 'Преподаватель',
        ];
    }
}
