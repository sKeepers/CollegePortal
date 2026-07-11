<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\EducationProgram;
use App\Models\Group;
use App\Models\ScheduleEntry;
use App\Models\ScheduleLesson;
use App\Models\Specialty;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingLoad;
use App\Models\TeachingLoadItem;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleEngineApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->createApiUser(roleCode: 'study'));
    }

    public function test_preview_does_not_write_and_apply_creates_engine_and_legacy_lesson(): void
    {
        $context = $this->context();
        $payload = $this->payload($context);

        $this->postJson('/api/schedule/preview', $payload)
            ->assertOk()
            ->assertJsonPath('can_apply', true)
            ->assertJsonPath('blocking_count', 0)
            ->assertJsonPath('warning_count', 0);

        $this->assertDatabaseCount('schedule_entries', 0);
        $this->assertDatabaseCount('schedule_lessons', 0);

        $this->postJson('/api/schedule/apply', $payload)
            ->assertCreated()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('entry.group_id', $context['group']->id);

        $this->assertDatabaseHas('schedule_entries', [
            'group_id' => $context['group']->id,
            'teaching_load_item_id' => $context['loadItem']->id,
            'source' => 'schedule_engine',
        ]);
        $this->assertDatabaseHas('schedule_lessons', [
            'group_id' => $context['group']->id,
            'subject_id' => $context['subject']->id,
            'starts_at' => '09:00',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'schedule_entry_created']);
    }

    public function test_detects_teacher_group_and_classroom_conflicts(): void
    {
        $context = $this->context();
        $this->postJson('/api/schedule/apply', $this->payload($context))->assertCreated();

        $other = $this->context(prefix: 'ART', teacher: $context['teacher'], classroom: $context['classroom']);
        $payload = $this->payload($other, ['starts_at' => '09:30', 'ends_at' => '10:15']);

        $response = $this->postJson('/api/schedule/preview', $payload)->assertOk();
        $types = collect($response->json('conflicts'))->pluck('type')->all();

        $this->assertContains('teacher_busy', $types);
        $this->assertContains('classroom_busy', $types);
        $this->assertFalse($response->json('can_apply'));
    }

    public function test_rejects_subject_outside_teaching_load_and_warns_on_capacity_and_hours(): void
    {
        $context = $this->context(plannedHours: 2, classroomCapacity: 1, students: 3);
        $payload = $this->payload($context, ['ends_at' => '11:15']);

        $response = $this->postJson('/api/schedule/preview', $payload)->assertOk();
        $types = collect($response->json('conflicts'))->pluck('type')->all();

        $this->assertContains('classroom_capacity', $types);
        $this->assertContains('hours_over_plan', $types);
        $this->assertTrue($response->json('can_apply'));

        $otherSubject = Subject::create(['name' => 'Сольфеджио', 'code' => 'SOLF']);
        $badPayload = $this->payload($context, ['subject_id' => $otherSubject->id, 'teaching_load_item_id' => null]);
        $bad = $this->postJson('/api/schedule/preview', $badPayload)->assertOk();
        $this->assertContains('teaching_load', collect($bad->json('conflicts'))->pluck('type')->all());
        $this->assertFalse($bad->json('can_apply'));
    }

    public function test_replacement_cancel_restore_and_coverage(): void
    {
        $context = $this->context(plannedHours: 4);
        $entryId = $this->postJson('/api/schedule/apply', $this->payload($context))->assertCreated()->json('entry.id');
        $newClassroom = Classroom::create(['number' => '305', 'building' => 'Main', 'capacity' => 30]);

        $this->postJson("/api/schedule/entries/{$entryId}/replace-classroom", ['classroom_id' => $newClassroom->id])
            ->assertOk()
            ->assertJsonPath('data.classroom_id', $newClassroom->id)
            ->assertJsonPath('data.is_replacement', true);

        $coverage = $this->getJson('/api/schedule/coverage')->assertOk()->json('data.0');
        $this->assertSame(4, $coverage['planned_hours']);
        $this->assertSame(2, $coverage['scheduled_hours']);
        $this->assertSame('partially_scheduled', $coverage['status']);

        $this->postJson("/api/schedule/entries/{$entryId}/cancel")->assertOk()->assertJsonPath('data.status', 'canceled');
        $this->assertDatabaseMissing('schedule_lessons', ['schedule_entry_id' => $entryId]);

        $this->postJson("/api/schedule/entries/{$entryId}/restore")->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->assertDatabaseHas('schedule_lessons', ['schedule_entry_id' => $entryId]);
    }

    public function test_schedule_engine_requires_permissions(): void
    {
        $teacherUser = $this->createApiUser(roleCode: 'teacher');
        $this->withApiAuth($teacherUser);
        $context = $this->context();

        $this->postJson('/api/schedule/apply', $this->payload($context))->assertForbidden();
        $this->getJson('/api/schedule/conflicts')->assertForbidden();
        $this->getJson("/api/schedule/teacher/{$context['teacher']->id}")->assertOk();
    }

    private function context(string $prefix = 'MUS', ?Teacher $teacher = null, ?Classroom $classroom = null, int $plannedHours = 72, int $classroomCapacity = 30, int $students = 0): array
    {
        $specialty = Specialty::create(['code' => $prefix, 'name' => "{$prefix} specialty"]);
        $program = EducationProgram::create(['specialty_id' => $specialty->id, 'name' => "{$prefix} program", 'year_start' => 2026, 'study_form' => 'Очная']);
        $curriculum = Curriculum::create(['education_program_id' => $program->id, 'name' => "{$prefix} curriculum", 'year_start' => 2026, 'status' => 'active']);
        $group = Group::create(['name' => "{$prefix}-101", 'specialty' => $prefix, 'education_program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'course' => 1, 'year_start' => 2026]);
        $teacher ??= Teacher::create(['last_name' => "{$prefix}ов", 'first_name' => 'Петр']);
        $subject = Subject::create(['name' => "{$prefix} theory", 'code' => "{$prefix}-101"]);
        $classroom ??= Classroom::create(['number' => "{$prefix}-201", 'building' => 'Main', 'capacity' => $classroomCapacity]);
        for ($i = 0; $i < $students; $i++) {
            $group->students()->create(['last_name' => "Student{$i}", 'first_name' => 'Test', 'email' => "student{$i}-{$prefix}@example.test", 'status' => 'active']);
        }
        $curriculumSubject = CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'semester' => 1, 'subject_id' => $subject->id, 'total_hours' => $plannedHours, 'sequence' => 1]);
        $load = TeachingLoad::create(['academic_year' => '2026/2027', 'teacher_id' => null, 'curriculum_id' => $curriculum->id, 'group_id' => $group->id, 'status' => 'draft']);
        $loadItem = TeachingLoadItem::create(['teaching_load_id' => $load->id, 'curriculum_subject_id' => $curriculumSubject->id, 'subject_id' => $subject->id, 'group_id' => $group->id, 'teacher_id' => $teacher->id, 'semester' => 1, 'hours_total' => $plannedHours, 'planned_hours' => $plannedHours, 'assigned_hours' => $plannedHours, 'unassigned_hours' => 0, 'overassigned_hours' => 0, 'load_type' => 'Аудиторная', 'assignment_status' => 'assigned', 'source' => 'curriculum_engine']);

        return compact('group', 'teacher', 'subject', 'classroom', 'loadItem');
    }

    private function payload(array $context, array $overrides = []): array
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
            'teacher_id' => $context['teacher']->id,
            'classroom_id' => $context['classroom']->id,
            'teaching_load_item_id' => $context['loadItem']->id,
            'status' => 'scheduled',
            'comment' => 'Тема занятия',
            ...$overrides,
        ];
    }
}
