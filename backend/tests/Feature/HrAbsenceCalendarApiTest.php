<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Department;
use App\Models\EducationProgram;
use App\Models\Employee;
use App\Models\Group;
use App\Models\Person;
use App\Models\Position;
use App\Models\ScheduleEntry;
use App\Models\Specialty;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingLoad;
use App\Models\TeachingLoadItem;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrAbsenceCalendarApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_period_preview_apply_calendar_and_overlap_conflict(): void
    {
        $this->withApiAuth($this->createApiUser(roleCode: 'hr'));
        $ctx = $this->context();

        $preview = $this->postJson("/api/hr/employees/{$ctx['employee']->id}/status-periods/preview", [
            'status' => 'vacation',
            'date_from' => '2026-09-02',
            'date_to' => '2026-09-03',
            'reason' => 'Отпуск',
        ])->assertOk()->assertJsonPath('can_apply', true)->assertJsonPath('affected_lessons_count', 1)->json();

        $this->assertDatabaseCount('employee_status_periods', 0);

        $periodId = $this->postJson("/api/hr/employees/{$ctx['employee']->id}/status-periods/apply", [
            'status' => 'vacation',
            'date_from' => '2026-09-02',
            'date_to' => '2026-09-03',
        ])->assertOk()->assertJsonPath('data.status', 'vacation')->json('data.id');

        $this->assertDatabaseHas('hr_events', ['event_type' => 'replacement_required', 'employee_status_period_id' => $periodId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee_status_period_applied', 'module' => 'hr']);

        $this->postJson("/api/hr/employees/{$ctx['employee']->id}/status-periods/preview", [
            'status' => 'sick_leave',
            'date_from' => '2026-09-03',
            'date_to' => '2026-09-04',
        ])->assertOk()->assertJsonPath('can_apply', false)->assertJsonPath('conflicts.0.type', 'period_overlap');

        $this->getJson('/api/hr/calendar?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.periods.0.affected_lessons_count', 1);
    }

    public function test_affected_lessons_candidates_replacement_and_cancel(): void
    {
        $this->withApiAuth($this->createApiUser(roleCode: 'hr'));
        $ctx = $this->context();
        $candidate = $this->candidateTeacher($ctx);

        $periodId = $this->postJson("/api/hr/employees/{$ctx['employee']->id}/status-periods/apply", [
            'status' => 'sick_leave',
            'date_from' => '2026-09-02',
            'date_to' => '2026-09-02',
        ])->assertOk()->json('data.id');

        $lessonId = $this->getJson("/api/hr/status-periods/{$periodId}/affected-lessons")
            ->assertOk()
            ->assertJsonPath('data.0.subject', 'История искусства')
            ->json('data.0.id');

        $this->getJson("/api/hr/replacements/candidates/{$lessonId}/{$ctx['employee']->id}")
            ->assertOk()
            ->assertJsonPath('data.0.teacher_id', $candidate->id)
            ->assertJsonPath('data.0.reasons.schedule_conflict', false);

        $items = [['schedule_entry_id' => $lessonId, 'teacher_id' => $candidate->id]];
        $this->postJson('/api/hr/replacements/preview', ['items' => $items])
            ->assertOk()
            ->assertJsonPath('can_apply', true);

        $this->postJson('/api/hr/replacements/apply', ['items' => $items])
            ->assertOk()
            ->assertJsonPath('data.applied', 1);

        $this->assertDatabaseHas('schedule_entries', ['id' => $lessonId, 'teacher_id' => $candidate->id, 'is_replacement' => true]);
        $this->assertDatabaseHas('hr_events', ['event_type' => 'replacement_assigned', 'schedule_entry_id' => $lessonId]);

        $this->postJson("/api/hr/status-periods/{$periodId}/cancel", ['reason' => 'Ошибка'])
            ->assertOk()
            ->assertJsonPath('data.period_status', 'cancelled');
    }

    public function test_candidate_with_schedule_conflict_is_marked_and_reports_export_csv(): void
    {
        $this->withApiAuth($this->createApiUser(roleCode: 'hr'));
        $ctx = $this->context();
        $candidate = $this->candidateTeacher($ctx);
        ScheduleEntry::create([...$this->entryPayload($ctx, $candidate), 'source' => 'manual']);
        $periodId = $ctx['employee']->statusPeriods()->create(['status' => 'business_trip', 'period_status' => 'active', 'date_from' => '2026-09-02', 'date_to' => '2026-09-02'])->id;

        $lessonId = $this->getJson("/api/hr/status-periods/{$periodId}/affected-lessons")->assertOk()->json('data.0.id');
        $this->getJson("/api/hr/replacements/candidates/{$lessonId}/{$ctx['employee']->id}")
            ->assertOk()
            ->assertJsonPath('data.0.reasons.schedule_conflict', true);

        $this->get('/api/hr/reports/absences.csv?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertSee('Сотрудник');
    }

    public function test_hr_calendar_permissions(): void
    {
        $this->withApiAuth($this->createApiUser(roleCode: 'teacher'))->getJson('/api/hr/calendar')->assertOk()->assertJsonPath('data.summary.total', 0);
        $this->withApiAuth($this->createApiUser(roleCode: 'student'))->getJson('/api/hr/calendar')->assertForbidden();
        $this->withApiAuth($this->createApiUser(roleCode: 'director'))->getJson('/api/hr/calendar')->assertOk();
    }

    private function context(): array
    {
        $department = Department::create(['code' => 'music', 'name' => 'Музыкальное отделение']);
        $position = Position::create(['code' => 'teacher', 'name' => 'Преподаватель']);
        $person = Person::create(['last_name' => 'Иванова', 'first_name' => 'Мария', 'status' => 'active']);
        $teacher = Teacher::create(['person_id' => $person->id, 'last_name' => 'Иванова', 'first_name' => 'Мария', 'is_active' => true]);
        $employee = Employee::create(['person_id' => $person->id, 'employee_number' => 'T-100', 'status' => 'active', 'employment_type' => 'full_time', 'hired_at' => '2026-01-01', 'primary_department_id' => $department->id, 'primary_position_id' => $position->id, 'is_teacher' => true]);
        $specialty = Specialty::create(['code' => 'ART', 'name' => 'Искусство']);
        $program = EducationProgram::create(['specialty_id' => $specialty->id, 'name' => 'Фортепиано', 'year_start' => 2026]);
        $curriculum = Curriculum::create(['education_program_id' => $program->id, 'name' => 'Учебный план', 'year_start' => 2026, 'status' => 'active']);
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Искусство', 'education_program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'course' => 1, 'year_start' => 2026]);
        $subject = Subject::create(['name' => 'История искусства', 'code' => 'ART-101']);
        $classroom = Classroom::create(['number' => '201', 'building' => 'Main', 'capacity' => 20]);
        $curriculumSubject = CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'semester' => 1, 'subject_id' => $subject->id, 'total_hours' => 72, 'sequence' => 1]);
        $load = TeachingLoad::create(['academic_year' => '2026/2027', 'curriculum_id' => $curriculum->id, 'group_id' => $group->id, 'status' => 'draft']);
        $loadItem = TeachingLoadItem::create(['teaching_load_id' => $load->id, 'curriculum_subject_id' => $curriculumSubject->id, 'subject_id' => $subject->id, 'group_id' => $group->id, 'teacher_id' => $teacher->id, 'semester' => 1, 'hours_total' => 72, 'planned_hours' => 72, 'assigned_hours' => 72, 'unassigned_hours' => 0, 'overassigned_hours' => 0, 'load_type' => 'Аудиторная', 'assignment_status' => 'assigned', 'source' => 'curriculum_engine']);
        $entry = ScheduleEntry::create($this->entryPayload(compact('group', 'subject', 'classroom', 'loadItem'), $teacher));
        return compact('department', 'position', 'person', 'teacher', 'employee', 'group', 'subject', 'classroom', 'loadItem', 'entry');
    }

    private function candidateTeacher(array $ctx): Teacher
    {
        $person = Person::create(['last_name' => 'Петров', 'first_name' => 'Олег', 'status' => 'active']);
        $teacher = Teacher::create(['person_id' => $person->id, 'last_name' => 'Петров', 'first_name' => 'Олег', 'is_active' => true]);
        $teacher->subjects()->attach($ctx['subject']->id);
        Employee::create(['person_id' => $person->id, 'employee_number' => 'T-200', 'status' => 'active', 'employment_type' => 'full_time', 'hired_at' => '2026-01-01', 'primary_department_id' => $ctx['department']->id, 'primary_position_id' => $ctx['position']->id, 'is_teacher' => true]);
        return $teacher;
    }

    private function entryPayload(array $ctx, Teacher $teacher): array
    {
        return [
            'academic_year' => '2026/2027',
            'semester' => 1,
            'date' => '2026-09-02',
            'day_of_week' => 3,
            'lesson_number' => 1,
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'group_id' => $ctx['group']->id,
            'subject_id' => $ctx['subject']->id,
            'teacher_id' => $teacher->id,
            'classroom_id' => $ctx['classroom']->id,
            'teaching_load_item_id' => $ctx['loadItem']->id,
            'status' => 'scheduled',
            'source' => 'schedule_engine',
        ];
    }
}
