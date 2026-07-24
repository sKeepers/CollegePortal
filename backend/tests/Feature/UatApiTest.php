<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\UatFeedback;
use App\Models\UatTestRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_run_update_result_upload_screenshot_and_complete(): void
    {
        Storage::fake('local');
        $manager = $this->userWithPermissions('study', ['uat.manage']);
        $tester = User::factory()->create(['email' => 'study.uat@college-portal.local', 'is_active' => true]);

        $runId = $this->withApiAuth($manager)->postJson('/api/admin/uat/runs', [
            'title' => 'UAT учебная часть',
            'role_code' => 'study',
            'tester_user_id' => $tester->id,
        ])->assertCreated()
            ->assertJsonPath('data.role_code', 'study')
            ->assertJsonPath('data.status', 'in_progress')
            ->json('data.id');

        $run = UatTestRun::with('results')->findOrFail($runId);
        $this->assertGreaterThan(5, $run->results->count());

        $result = $run->results()->firstOrFail();
        $this->withApiAuth($manager)->post("/api/admin/uat/runs/{$run->id}/results/{$result->id}", [
            'status' => 'failed',
            'comment' => 'Не видно конфликты',
            'actual_result' => 'Панель пустая',
            'screenshot' => UploadedFile::fake()->image('screen.png'),
        ])->assertOk()->assertJsonPath('data.progress.failed', 1);

        $result->refresh();
        $this->assertNotNull($result->screenshot_path);
        Storage::disk('local')->assertExists($result->screenshot_path);
        $this->withApiAuth($manager)->get("/api/admin/uat/results/{$result->id}/screenshot")->assertOk();

        $this->withApiAuth($manager)->postJson("/api/admin/uat/runs/{$run->id}/complete", ['summary' => 'Готово к разбору'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('audit_logs', ['module' => 'uat', 'action' => 'test_result_updated']);
    }

    public function test_feedback_creation_stores_version_build_and_private_screenshot(): void
    {
        Storage::fake('local');
        $teacher = $this->userWithPermissions('teacher', []);

        $feedbackId = $this->withApiAuth($teacher)->post('/api/uat/feedback', [
            'role_code' => 'teacher',
            'category' => 'error',
            'severity' => 'high',
            'title' => 'Журнал не открывается',
            'description' => 'После клика вижу ошибку',
            'expected_result' => 'Открывается журнал занятия',
            'actual_result' => 'Ошибка',
            'page_url' => '/journal?mode=today',
            'app_version' => '0.7.0-dev',
            'build_hash' => 'abc1234',
            'environment' => 'development',
            'screenshot' => UploadedFile::fake()->image('feedback.png'),
        ])->assertCreated()
            ->assertJsonPath('data.app_version', '0.7.0-dev')
            ->assertJsonPath('data.build_hash', 'abc1234')
            ->json('data.id');

        $feedback = UatFeedback::findOrFail($feedbackId);
        Storage::disk('local')->assertExists($feedback->screenshot_path);
        $manager = $this->userWithPermissions('admin', ['uat.manage']);
        $this->withApiAuth($manager)->get("/api/admin/uat/feedback/{$feedback->id}/screenshot")->assertOk();
    }

    public function test_admin_can_export_runs_and_feedback(): void
    {
        $admin = $this->userWithPermissions('admin', ['uat.manage']);
        $this->withApiAuth($admin)->postJson('/api/admin/uat/runs', ['title' => 'Director UAT', 'role_code' => 'director'])->assertCreated();
        $this->withApiAuth($admin)->postJson('/api/uat/feedback', [
            'role_code' => 'director', 'category' => 'ux', 'severity' => 'ux', 'title' => 'Мелкое замечание', 'description' => 'Текст',
        ])->assertCreated();

        $this->withApiAuth($admin)->get('/api/admin/uat/export/results.csv')->assertOk();
        $this->withApiAuth($admin)->get('/api/admin/uat/export/feedback.csv?failed_only=1')->assertOk();
    }

    public function test_manager_can_view_feedback_card_update_status_history_comments_and_github_issue(): void
    {
        Storage::fake('local');
        $manager = $this->userWithPermissions('study', ['uat.manage']);
        $author = $this->userWithPermissions('teacher', []);

        $feedbackId = $this->withApiAuth($author)->post('/api/uat/feedback', [
            'role_code' => 'teacher',
            'category' => 'access',
            'severity' => 'critical',
            'title' => 'Нет доступа к журналу',
            'description' => 'Открывается 403',
            'expected_result' => 'Журнал доступен',
            'actual_result' => '403',
            'page_url' => '/journal',
            'app_version' => '0.8.0-rc2',
            'build_hash' => 'build-uat',
            'environment' => 'development',
            'browser' => 'Firefox ESR',
            'screenshot' => UploadedFile::fake()->image('feedback-card.png'),
        ])->assertCreated()
            ->assertJsonPath('data.browser', 'Firefox ESR')
            ->json('data.id');

        $this->withApiAuth($manager)->getJson('/api/admin/uat/feedback?status=new&category=access&severity=critical&page=journal&version=0.8.0-rc2&q=403')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $feedbackId);

        $this->withApiAuth($manager)->putJson("/api/admin/uat/feedback/{$feedbackId}", [
            'status' => 'in_progress',
            'status_comment' => 'Взято в работу учебной частью',
            'github_issue_number' => 42,
            'github_issue_url' => 'https://github.com/sKeepers/CollegePortal/issues/42',
            'github_issue_status' => 'open',
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.github_issue_number', 42);

        $this->withApiAuth($manager)->postJson("/api/admin/uat/feedback/{$feedbackId}/comments", [
            'type' => 'developer',
            'comment' => 'Проверить permission journal.view.',
        ])->assertOk()
            ->assertJsonPath('data.comments.0.type', 'developer');

        $this->withApiAuth($manager)->getJson("/api/admin/uat/feedback/{$feedbackId}")
            ->assertOk()
            ->assertJsonPath('data.id', $feedbackId)
            ->assertJsonPath('data.status_history.0.old_status', 'new')
            ->assertJsonPath('data.status_history.0.new_status', 'in_progress')
            ->assertJsonPath('data.status_history.0.comment', 'Взято в работу учебной частью')
            ->assertJsonPath('data.comments.0.comment', 'Проверить permission journal.view.')
            ->assertJsonPath('data.has_screenshot', true);

        $this->withApiAuth($manager)->get("/api/admin/uat/feedback/{$feedbackId}/screenshot")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['module' => 'uat', 'action' => 'feedback_comment_created']);
    }

    public function test_feedback_registry_filters_by_author_and_period(): void
    {
        $manager = $this->userWithPermissions('director', ['uat.manage']);
        $teacher = $this->userWithPermissions('teacher', []);
        $student = $this->userWithPermissions('student', []);

        $teacherFeedbackId = $this->withApiAuth($teacher)->postJson('/api/uat/feedback', [
            'role_code' => 'teacher',
            'category' => 'ux',
            'severity' => 'ux',
            'title' => 'Кнопка слишком далеко',
            'description' => 'Нужно меньше кликов',
            'page_url' => '/schedule',
            'app_version' => '0.8.0-rc2',
        ])->assertCreated()->json('data.id');

        $this->withApiAuth($student)->postJson('/api/uat/feedback', [
            'role_code' => 'student',
            'category' => 'error',
            'severity' => 'medium',
            'title' => 'Не видно QR',
            'description' => 'Пустой блок',
            'page_url' => '/m/student/pass',
            'app_version' => '0.8.0-rc2',
        ])->assertCreated();

        $today = now()->toDateString();
        $this->withApiAuth($manager)->getJson("/api/admin/uat/feedback?author_id={$teacher->id}&date_from={$today}&date_to={$today}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $teacherFeedbackId);
    }

    public function test_teacher_student_and_security_cannot_access_uat_registry(): void
    {
        foreach (['teacher', 'student', 'security'] as $role) {
            $user = $this->userWithPermissions($role, []);
            $this->withApiAuth($user)->getJson('/api/admin/uat/config')->assertForbidden();
            $this->withApiAuth($user)->getJson('/api/admin/uat/feedback')->assertForbidden();
        }
    }

    private function userWithPermissions(string $roleCode, array $permissions): User
    {
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => ucfirst($roleCode)]);
        $ids = collect($permissions)->map(fn (string $permission) => Permission::query()->firstOrCreate(
            ['code' => $permission],
            ['name' => $permission, 'module' => 'UAT', 'description' => null, 'system' => true, 'active' => true],
        )->id);
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
