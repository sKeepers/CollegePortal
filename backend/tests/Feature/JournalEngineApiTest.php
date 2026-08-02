<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Classroom;
use App\Models\Group;
use App\Models\JournalLesson;
use App\Models\JournalEditRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ScheduleEntry;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JournalEngineApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-12 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_open_from_schedule_is_idempotent_and_creates_student_roster(): void
    {
        [$entry] = $this->journalFixture();
        $admin = $this->createApiUser(roleCode: 'admin');

        $first = $this->withApiAuth($admin)->postJson("/api/journal/from-schedule/{$entry->id}/open")
            ->assertCreated()
            ->assertJsonPath('data.schedule_entry_id', $entry->id)
            ->assertJsonCount(2, 'data.attendance');

        $this->withApiAuth($admin)->postJson("/api/journal/from-schedule/{$entry->id}/open")
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonCount(2, 'data.attendance');

        $this->assertDatabaseCount('journal_lessons', 1);
        $this->assertDatabaseCount('journal_attendance', 2);
        $this->assertDatabaseHas('audit_logs', ['module' => 'journal', 'action' => 'open_from_schedule']);
    }

    public function test_open_from_legacy_schedule_is_idempotent_and_creates_student_roster(): void
    {
        [, $group, $subject, $teacher, $classroom] = $this->journalFixture();
        $scheduleLesson = ScheduleLesson::create([
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'lesson_date' => '2026-07-12',
            'starts_at' => '09:00:00',
            'ends_at' => '10:30:00',
            'topic' => 'Гаммы и интервалы',
        ]);
        $admin = $this->createApiUser(roleCode: 'admin');

        $first = $this->withApiAuth($admin)->postJson("/api/journal/from-legacy-schedule/{$scheduleLesson->id}/open")
            ->assertCreated()
            ->assertJsonPath('data.legacy_schedule_lesson_id', $scheduleLesson->id)
            ->assertJsonPath('data.topic', 'Гаммы и интервалы')
            ->assertJsonCount(2, 'data.attendance');

        $this->withApiAuth($admin)->postJson("/api/journal/from-legacy-schedule/{$scheduleLesson->id}/open")
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('journal_lessons', 1);
        $this->assertDatabaseHas('audit_logs', ['module' => 'journal', 'action' => 'open_from_legacy_schedule']);
    }

    public function test_it_saves_topic_homework_attendance_and_grades(): void
    {
        [$entry, , , , , $students] = $this->journalFixture();
        $admin = $this->createApiUser(roleCode: 'admin');
        $lessonId = $this->withApiAuth($admin)->postJson("/api/journal/from-schedule/{$entry->id}/open")->json('data.id');

        $this->withApiAuth($admin)->putJson("/api/journal/lessons/{$lessonId}", [
            'topic' => 'Тема 1',
            'homework' => 'Выучить гаммы',
            'teacher_comment' => 'Работаем спокойно',
        ])->assertOk()->assertJsonPath('data.topic', 'Тема 1');

        $this->withApiAuth($admin)->putJson("/api/journal/lessons/{$lessonId}/attendance", [
            'attendance' => [
                ['student_id' => $students[0]->id, 'status' => 'late', 'minutes_late' => 7],
                ['student_id' => $students[1]->id, 'status' => 'absent'],
            ],
        ])->assertOk()->assertJsonPath('data.attendance.0.status', 'late');

        $this->withApiAuth($admin)->putJson("/api/journal/lessons/{$lessonId}/grades", [
            'grades' => [
                ['student_id' => $students[0]->id, 'value' => '5', 'weight' => 1],
            ],
        ])->assertOk()->assertJsonPath('data.grades.0.value', '5');

        $this->assertDatabaseHas('journal_attendance', ['journal_lesson_id' => $lessonId, 'student_id' => $students[0]->id, 'status' => 'late']);
        $this->assertDatabaseHas('journal_grades', ['journal_lesson_id' => $lessonId, 'student_id' => $students[0]->id, 'value' => '5']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'journal', 'action' => 'grade_update']);
    }

    public function test_attendance_suggestion_preview_does_not_write_until_apply(): void
    {
        [$entry, , , , , $students] = $this->journalFixture();
        $admin = $this->createApiUser(roleCode: 'admin');
        $lessonId = $this->withApiAuth($admin)->postJson("/api/journal/from-schedule/{$entry->id}/open")->json('data.id');

        AccessEvent::create([
            'entity_type' => 'student',
            'entity_id' => $students[0]->id,
            'direction' => 'in',
            'event_time' => '2026-07-12 08:55:00',
            'result' => 'allowed',
        ]);

        $this->withApiAuth($admin)->getJson("/api/journal/lessons/{$lessonId}/attendance-suggestion")
            ->assertOk()
            ->assertJsonPath('data.0.suggestion', 'probably_present');

        $this->assertDatabaseMissing('journal_attendance', ['journal_lesson_id' => $lessonId, 'student_id' => $students[0]->id, 'source' => 'access_gate_suggestion']);

        $this->withApiAuth($admin)->postJson("/api/journal/lessons/{$lessonId}/attendance-suggestion/apply")
            ->assertOk();

        $this->assertDatabaseHas('journal_attendance', ['journal_lesson_id' => $lessonId, 'student_id' => $students[0]->id, 'source' => 'access_gate_suggestion']);
    }

    public function test_teacher_cannot_edit_other_teacher_lesson(): void
    {
        [$entry, , , $teacher] = $this->journalFixture();
        $ownerUser = User::factory()->create(['is_active' => true]);
        $teacher->update(['user_id' => $ownerUser->id]);
        $otherUser = User::factory()->create(['is_active' => true]);
        Teacher::create(['user_id' => $otherUser->id, 'last_name' => 'Other', 'first_name' => 'Teacher']);
        $teacherRole = $this->roleWithPermissions('teacher', ['journal.view', 'journal.edit']);
        $ownerUser->update(['role_id' => $teacherRole->id]);
        $otherUser->update(['role_id' => $teacherRole->id]);

        $lessonId = $this->withApiAuth($ownerUser)->postJson("/api/journal/from-schedule/{$entry->id}/open")->json('data.id');

        $this->withApiAuth($otherUser)->putJson("/api/journal/lessons/{$lessonId}", ['topic' => 'Чужая тема'])
            ->assertForbidden();
    }

    public function test_signed_lesson_blocks_regular_teacher_and_reopen_permission_allows_fix(): void
    {
        [$entry, , , $teacher] = $this->journalFixture();
        $teacherUser = User::factory()->create(['is_active' => true]);
        $teacher->update(['user_id' => $teacherUser->id]);
        $teacherRole = $this->roleWithPermissions('teacher', ['journal.view', 'journal.edit', 'journal.sign']);
        $teacherUser->update(['role_id' => $teacherRole->id]);
        $lessonId = $this->withApiAuth($teacherUser)->postJson("/api/journal/from-schedule/{$entry->id}/open")->json('data.id');

        $this->withApiAuth($teacherUser)->putJson("/api/journal/lessons/{$lessonId}", ['topic' => 'Тема перед подписью'])->assertOk();
        $this->withApiAuth($teacherUser)->postJson("/api/journal/lessons/{$lessonId}/sign")->assertOk()->assertJsonPath('data.status', 'signed');
        $this->withApiAuth($teacherUser)->putJson("/api/journal/lessons/{$lessonId}", ['topic' => 'После подписи'])->assertUnprocessable();

        $admin = $this->createApiUser(roleCode: 'admin');
        $this->withApiAuth($admin)->postJson("/api/journal/lessons/{$lessonId}/reopen", ['reason' => 'Исправление после проверки'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reopened');
        $this->withApiAuth($admin)->putJson("/api/journal/lessons/{$lessonId}", ['topic' => 'Исправлено учебной частью'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['module' => 'journal', 'action' => 'reopen']);
    }

    public function test_teacher_can_request_and_admin_can_approve_edit_for_signed_lesson(): void
    {
        [$entry, , , $teacher] = $this->journalFixture();
        $teacherUser = User::factory()->create(['is_active' => true]);
        $teacher->update(['user_id' => $teacherUser->id]);
        $teacherUser->update(['role_id' => $this->roleWithPermissions('teacher', ['journal.view', 'journal.edit'])->id]);
        $admin = $this->createApiUser(roleCode: 'admin');
        $lessonId = $this->withApiAuth($admin)->postJson("/api/journal/from-schedule/{$entry->id}/open")->json('data.id');
        $this->withApiAuth($admin)->putJson("/api/journal/lessons/{$lessonId}", ['topic' => 'Подписанная тема'])->assertOk();
        $this->withApiAuth($admin)->postJson("/api/journal/lessons/{$lessonId}/sign")->assertOk();

        $this->withApiAuth($teacherUser)->postJson("/api/journal/lessons/{$lessonId}/edit-requests", ['reason' => 'Исправить тему занятия'])
            ->assertOk()
            ->assertJsonPath('data.edit_requests.0.status', JournalEditRequest::STATUS_PENDING);
        $requestId = JournalEditRequest::query()->value('id');

        $this->withApiAuth($admin)->getJson('/api/journal/edit-requests/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requestId)
            ->assertJsonPath('data.0.journal_lesson_id', $lessonId);

        $this->withApiAuth($admin)->postJson("/api/journal/edit-requests/{$requestId}/review", ['approved' => true])
            ->assertOk()
            ->assertJsonPath('data.status', JournalLesson::STATUS_REOPENED);

        $this->withApiAuth($admin)->getJson('/api/journal/edit-requests/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('journal_edit_requests', ['id' => $requestId, 'status' => JournalEditRequest::STATUS_APPROVED]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'journal', 'action' => 'edit_request_approved']);
    }

    public function test_private_files_and_csv_export(): void
    {
        Storage::fake('local');
        [$entry] = $this->journalFixture();
        $admin = $this->createApiUser(roleCode: 'admin');
        $lessonId = $this->withApiAuth($admin)->postJson("/api/journal/from-schedule/{$entry->id}/open")->json('data.id');

        $this->withApiAuth($admin)->postJson("/api/journal/lessons/{$lessonId}/files", [
            'file' => UploadedFile::fake()->create('plan.pdf', 64, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.original_name', 'plan.pdf');

        $filePath = \App\Models\JournalLessonFile::firstOrFail()->path;
        Storage::disk('local')->assertExists($filePath);

        $this->withApiAuth($admin)->get("/api/journal/lessons/{$lessonId}/export.csv")->assertOk();
        $this->withApiAuth($admin)->get("/api/journal/export/group.csv?group_id={$entry->group_id}")->assertOk();
        $this->withApiAuth($admin)->get("/api/journal/export/teacher.csv?teacher_id={$entry->teacher_id}")->assertOk();
    }

    public function test_teacher_scope_and_control_mode_for_study_office(): void
    {
        [$entry, , , $teacher] = $this->journalFixture();
        [$otherEntry] = $this->journalFixture(['starts_at' => '11:00:00', 'ends_at' => '12:30:00']);
        $teacherUser = User::factory()->create(['is_active' => true]);
        $teacher->update(['user_id' => $teacherUser->id]);
        $teacherRole = $this->roleWithPermissions('teacher', ['journal.view', 'journal.edit']);
        $teacherUser->update(['role_id' => $teacherRole->id]);
        $admin = $this->createApiUser(roleCode: 'admin');
        $study = User::factory()->create(['is_active' => true]);
        $studyRole = $this->roleWithPermissions('study', ['journal.view', 'journal.view_all']);
        $study->update(['role_id' => $studyRole->id]);

        $ownLessonId = $this->withApiAuth($admin)->postJson("/api/journal/from-schedule/{$entry->id}/open")
            ->assertCreated()
            ->json('data.id');
        $otherLessonId = $this->withApiAuth($admin)->postJson("/api/journal/from-schedule/{$otherEntry->id}/open")
            ->assertCreated()
            ->json('data.id');

        $teacherResponse = $this->withApiAuth($teacherUser)->getJson('/api/journal/lessons?mode=week')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertSame([$ownLessonId], collect($teacherResponse->json('data'))->pluck('id')->all());
        $this->assertNotContains($otherLessonId, collect($teacherResponse->json('data'))->pluck('id')->all());

        $this->withApiAuth($teacherUser)->getJson('/api/journal/lessons?mode=mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownLessonId);

        $teacherControlResponse = $this->withApiAuth($teacherUser)->getJson('/api/journal/lessons?mode=control')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertSame([$ownLessonId], collect($teacherControlResponse->json('data'))->pluck('id')->all());

        $orphanTeacherUser = User::factory()->create(['is_active' => true, 'role_id' => $teacherRole->id]);
        $this->withApiAuth($orphanTeacherUser)->getJson('/api/journal/lessons?mode=week')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $studyResponse = $this->withApiAuth($study)->getJson('/api/journal/lessons?mode=control')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$ownLessonId, $otherLessonId], collect($studyResponse->json('data'))->pluck('id')->all());
    }

    public function test_attendance_suggestion_marks_left_before_end(): void
    {
        [$entry, , , , , $students] = $this->journalFixture();
        $admin = $this->createApiUser(roleCode: 'admin');
        $lessonId = $this->withApiAuth($admin)->postJson("/api/journal/from-schedule/{$entry->id}/open")->json('data.id');

        AccessEvent::create(['entity_type' => 'student', 'entity_id' => $students[0]->id, 'direction' => 'in', 'event_time' => '2026-07-12 08:55:00', 'result' => 'allowed']);
        AccessEvent::create(['entity_type' => 'student', 'entity_id' => $students[0]->id, 'direction' => 'out', 'event_time' => '2026-07-12 09:30:00', 'result' => 'allowed']);

        $this->withApiAuth($admin)->getJson("/api/journal/lessons/{$lessonId}/attendance-suggestion")
            ->assertOk()
            ->assertJsonPath('data.0.left_before_end', true);
    }

    public function test_cancelled_schedule_entry_does_not_create_regular_journal_lesson(): void
    {
        [$entry] = $this->journalFixture(['status' => 'cancelled']);
        $admin = $this->createApiUser(roleCode: 'admin');

        $this->withApiAuth($admin)->postJson("/api/journal/from-schedule/{$entry->id}/open")->assertUnprocessable();
        $this->assertDatabaseCount('journal_lessons', 0);
    }

    private function journalFixture(array $entryOverrides = []): array
    {
        $teacher = Teacher::create(['last_name' => 'Иванов', 'first_name' => 'Иван', 'is_active' => true]);
        $groupNumber = Group::query()->count() + 101;
        $group = Group::create(['name' => "ИСП-{$groupNumber}", 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026, 'curator_id' => $teacher->id]);
        $subject = Subject::create(['name' => 'Фортепиано', 'code' => 'PIANO-'.$groupNumber]);
        $classroom = Classroom::create(['number' => (string) $groupNumber, 'building' => 'А']);
        $students = collect([
            Student::create(['group_id' => $group->id, 'last_name' => 'Петров', 'first_name' => 'Петр', 'status' => 'active']),
            Student::create(['group_id' => $group->id, 'last_name' => 'Сидорова', 'first_name' => 'Анна', 'status' => 'active']),
        ]);
        $entry = ScheduleEntry::create(array_merge([
            'academic_year' => '2026/2027',
            'semester' => 1,
            'date' => '2026-07-12',
            'day_of_week' => 1,
            'lesson_number' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:30:00',
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'status' => 'published',
            'source' => 'manual',
        ], $entryOverrides));

        return [$entry, $group, $subject, $teacher, $classroom, $students];
    }

    private function roleWithPermissions(string $code, array $permissions): Role
    {
        $role = Role::query()->firstOrCreate(['code' => $code], ['name' => ucfirst($code)]);
        $ids = collect($permissions)->map(fn (string $permission) => Permission::query()->firstOrCreate(
            ['code' => $permission],
            ['name' => $permission, 'module' => 'Journal', 'description' => null, 'system' => true, 'active' => true],
        )->id);
        $role->permissions()->sync($ids);

        return $role;
    }
}
