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
        $tester = User::factory()->create(['email' => 'study@local', 'is_active' => true]);

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
            'app_version' => '0.8.0-rc2',
            'build_hash' => 'abc1234',
            'environment' => 'development',
            'screenshot' => UploadedFile::fake()->image('feedback.png'),
        ])->assertCreated()
            ->assertJsonPath('data.app_version', '0.8.0-rc2')
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

    public function test_teacher_student_and_security_cannot_access_uat_registry(): void
    {
        foreach (['teacher', 'student', 'security'] as $role) {
            $user = $this->userWithPermissions($role, []);
            $this->withApiAuth($user)->getJson('/api/admin/uat/config')->assertForbidden();
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
