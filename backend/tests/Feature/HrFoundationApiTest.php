<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Department;
use App\Models\EducationProgram;
use App\Models\Employee;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Person;
use App\Models\Position;
use App\Models\Specialty;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingLoad;
use App\Models\TeachingLoadItem;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class HrFoundationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_employee_can_be_created_for_existing_and_new_person(): void
    {
        $this->withApiAuth($this->createApiUser(roleCode: 'hr'));
        $department = Department::create(['code' => 'study', 'name' => 'Учебная часть']);
        $position = Position::create(['code' => 'methodist', 'name' => 'Методист']);
        $person = Person::create(['last_name' => 'Иванова', 'first_name' => 'Мария', 'status' => 'active']);

        $this->postJson('/api/employees', [
            'person_id' => $person->id,
            'employee_number' => 'EMP-001',
            'status' => 'active',
            'employment_type' => 'full_time',
            'work_schedule_code' => 'weekday_0900_1700',
            'hired_at' => '2026-09-01',
            'primary_department_id' => $department->id,
            'primary_position_id' => $position->id,
            'workload_rate' => 1,
        ])->assertOk()->assertJsonPath('data.person.id', $person->id)->assertJsonPath('data.work_schedule_code', 'weekday_0900_1700');

        $this->postJson('/api/employees', [
            'employee_number' => 'EMP-002',
            'last_name' => 'Петров',
            'first_name' => 'Олег',
            'email' => 'petrov@example.test',
            'status' => 'active',
            'employment_type' => 'part_time',
            'hired_at' => '2026-09-02',
        ])->assertOk()->assertJsonPath('data.full_name', 'Петров Олег');

        $this->assertDatabaseHas('employees', ['employee_number' => 'EMP-001', 'person_id' => $person->id, 'work_schedule_code' => 'weekday_0900_1700']);
        $this->assertDatabaseHas('people', ['email' => 'petrov@example.test']);
        $this->assertDatabaseCount('digital_identities', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee_hired', 'module' => 'hr']);
    }

    public function test_hr_explicitly_issues_employee_digital_pass_with_a_safe_audit_record(): void
    {
        $this->withApiAuth($this->createApiUser(roleCode: 'hr'));
        $employee = Employee::create([
            'person_id' => Person::create([
                'last_name' => 'Соколова',
                'first_name' => 'Ирина',
                'phone' => '+79990000003',
                'email' => 'sokolova@example.test',
                'status' => 'active',
            ])->id,
            'employee_number' => 'EMP-PASS-001',
            'status' => 'active',
            'employment_type' => 'full_time',
            'hired_at' => '2026-09-01',
        ]);

        $response = $this->postJson("/api/employees/{$employee->id}/digital-pass")
            ->assertOk()
            ->assertJsonPath('data.entity_type', DigitalIdentity::ENTITY_EMPLOYEE)
            ->assertJsonPath('data.entity_id', $employee->id)
            ->assertJsonPath('data.owner.last_name', 'Соколова');

        $identityId = $response->json('data.id');
        $this->assertDatabaseHas('digital_identities', [
            'id' => $identityId,
            'entity_type' => DigitalIdentity::ENTITY_EMPLOYEE,
            'entity_id' => $employee->id,
            'status' => DigitalIdentity::STATUS_ACTIVE,
        ]);

        $audit = AuditLog::query()->where('action', 'employee_digital_pass_issued')->firstOrFail();
        $this->assertSame('hr', $audit->module);
        $this->assertArrayNotHasKey('token', $audit->new_values);
        $this->assertArrayNotHasKey('phone', $audit->new_values);
        $this->assertArrayNotHasKey('email', $audit->new_values);

        $this->withApiAuth($this->createApiUser(roleCode: 'teacher'))
            ->postJson("/api/employees/{$employee->id}/digital-pass")
            ->assertForbidden();
    }

    public function test_assignments_status_periods_and_dismissal_are_tracked(): void
    {
        $this->withApiAuth($this->createApiUser(roleCode: 'hr'));
        $department = Department::create(['code' => 'hr', 'name' => 'Кадровая служба']);
        $position = Position::create(['code' => 'specialist', 'name' => 'Специалист']);
        $employee = Employee::create([
            'person_id' => Person::create(['last_name' => 'Сидорова', 'first_name' => 'Анна', 'status' => 'active'])->id,
            'employee_number' => 'EMP-010',
            'status' => 'active',
            'employment_type' => 'full_time',
            'hired_at' => '2026-01-01',
        ]);

        $this->postJson("/api/employees/{$employee->id}/assignments", [
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employment_type' => 'full_time',
            'rate' => 1,
            'started_at' => '2026-01-01',
            'is_primary' => true,
        ])->assertOk()->assertJsonPath('data.primary_department.id', $department->id);

        $this->postJson("/api/employees/{$employee->id}/status-periods", [
            'status' => 'vacation',
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-10',
            'reason' => 'Ежегодный отпуск',
        ])->assertOk()->assertJsonPath('data.current_status', 'active');

        $employee->refresh();
        $this->assertSame('vacation', $employee->statusOn('2026-10-05'));
        $this->assertTrue($employee->isActiveOn('2026-09-15'));

        $this->deleteJson("/api/employees/{$employee->id}")->assertNoContent();
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'status' => 'dismissed']);
    }

    public function test_teacher_employee_status_adds_schedule_warning_without_blocking(): void
    {
        $this->withApiAuth($this->createApiUser(roleCode: 'study'));
        $context = $this->scheduleContext();
        $person = Person::create(['last_name' => 'Крылова', 'first_name' => 'Нина', 'status' => 'active']);
        $teacher = Teacher::create(['person_id' => $person->id, 'last_name' => 'Крылова', 'first_name' => 'Нина', 'is_active' => true]);
        $employee = Employee::create(['person_id' => $person->id, 'employee_number' => 'T-001', 'status' => 'active', 'employment_type' => 'full_time', 'hired_at' => '2026-09-01', 'is_teacher' => true]);
        $employee->statusPeriods()->create(['status' => 'sick_leave', 'date_from' => '2026-09-02', 'date_to' => '2026-09-05']);
        $context['loadItem']->update(['teacher_id' => $teacher->id]);

        $response = $this->postJson('/api/schedule/preview', $this->schedulePayload($context, $teacher))
            ->assertOk()
            ->assertJsonPath('can_apply', true);

        $types = collect($response->json('conflicts'))->pluck('type')->all();
        $this->assertContains('teacher_hr_unavailable', $types);
    }

    public function test_hr_permissions_and_universal_employee_import(): void
    {
        Department::create(['code' => 'administration', 'name' => 'Администрация']);
        $teacher = $this->createApiUser(roleCode: 'teacher');
        $this->withApiAuth($teacher)->getJson('/api/employees')->assertForbidden();

        $this->withApiAuth($this->createApiUser(roleCode: 'hr'));
        $this->getJson('/api/employees')->assertOk();

        $file = UploadedFile::fake()->createWithContent('employees.csv', "Фамилия;Имя;Email;Отделение;Должность;Активен\nОрлова;Вера;orlova@example.test;Администрация;Методист;1\nОрлова;Вера;orlova@example.test;Администрация;Методист;1\n");
        $jobId = $this->post('/api/admin/import/preview', ['data_type' => 'employees', 'file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.total_rows', 2)
            ->json('data.id');

        $mapping = [
            'last_name' => 'Фамилия',
            'first_name' => 'Имя',
            'email' => 'Email',
            'department' => 'Отделение',
            'position' => 'Должность',
            'status' => 'Активен',
        ];
        $this->postJson("/api/admin/import/{$jobId}/confirm", ['mode' => 'skip_duplicates', 'mapping' => $mapping])
            ->assertOk()
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.error_count', 0);

        $employee = Employee::query()->whereHas('person', fn ($query) => $query->where('email', 'orlova@example.test'))->firstOrFail();
        $this->assertDatabaseHas('people', ['email' => 'orlova@example.test']);
        $this->assertNull($employee->hired_at);
        $this->assertStringStartsWith('EMP-IMPORT-', $employee->employee_number);
        $this->assertDatabaseHas('positions', ['name' => 'Методист']);
    }

    private function scheduleContext(): array
    {
        $specialty = Specialty::create(['code' => 'HRM', 'name' => 'HR specialty']);
        $program = EducationProgram::create(['specialty_id' => $specialty->id, 'name' => 'HR program', 'year_start' => 2026]);
        $curriculum = Curriculum::create(['education_program_id' => $program->id, 'name' => 'HR curriculum', 'year_start' => 2026, 'status' => 'active']);
        $group = Group::create(['name' => 'HR-101', 'specialty' => 'HR', 'education_program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'course' => 1, 'year_start' => 2026]);
        $subject = Subject::create(['name' => 'История искусства', 'code' => 'ART-HR']);
        $classroom = Classroom::create(['number' => '201', 'building' => 'Main', 'capacity' => 30]);
        $curriculumSubject = CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'semester' => 1, 'subject_id' => $subject->id, 'total_hours' => 72, 'sequence' => 1]);
        $load = TeachingLoad::create(['academic_year' => '2026/2027', 'curriculum_id' => $curriculum->id, 'group_id' => $group->id, 'status' => 'draft']);
        $loadItem = TeachingLoadItem::create(['teaching_load_id' => $load->id, 'curriculum_subject_id' => $curriculumSubject->id, 'subject_id' => $subject->id, 'group_id' => $group->id, 'semester' => 1, 'hours_total' => 72, 'planned_hours' => 72, 'assigned_hours' => 72, 'unassigned_hours' => 0, 'overassigned_hours' => 0, 'load_type' => 'Аудиторная', 'assignment_status' => 'assigned', 'source' => 'curriculum_engine']);
        return compact('group', 'subject', 'classroom', 'loadItem');
    }

    private function schedulePayload(array $context, Teacher $teacher): array
    {
        return [
            'academic_year' => '2026/2027',
            'semester' => 1,
            'date' => '2026-09-02',
            'lesson_number' => 1,
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'group_id' => $context['group']->id,
            'subject_id' => $context['subject']->id,
            'teacher_id' => $teacher->id,
            'classroom_id' => $context['classroom']->id,
            'teaching_load_item_id' => $context['loadItem']->id,
            'status' => 'scheduled',
        ];
    }
}
