<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сброс пароля из карточки человека: новый пароль показывается один раз,
 * старый сразу перестает работать, в аудит пароль не попадает.
 */
class AccountResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Студент', 'code' => 'student']);
        $this->withApiAuth();
    }

    public function test_reset_returns_a_new_password_once_and_invalidates_the_old_one(): void
    {
        $student = $this->student();

        $first = $this->postJson('/api/admin/users/provision', [
            'profile_type' => 'student',
            'profile_id' => $student->id,
        ])->assertCreated()->json('data');

        $reset = $this->postJson('/api/admin/users/reset-password', [
            'profile_type' => 'student',
            'profile_id' => $student->id,
        ])->assertOk()->json('data');

        $this->assertSame($first['login'], $reset['login']);
        $this->assertNotSame($first['password'], $reset['password']);

        $user = User::query()->where('username', $reset['login'])->firstOrFail();
        $this->assertTrue(password_verify($reset['password'], $user->password));
        $this->assertFalse(password_verify($first['password'], $user->password));
    }

    public function test_reset_is_refused_when_there_is_no_account_yet(): void
    {
        $student = $this->student();

        $this->postJson('/api/admin/users/reset-password', [
            'profile_type' => 'student',
            'profile_id' => $student->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'У этого человека нет учетной записи. Сначала создайте ее.');
    }

    public function test_new_password_never_reaches_the_audit_log(): void
    {
        $student = $this->student();

        $this->postJson('/api/admin/users/provision', ['profile_type' => 'student', 'profile_id' => $student->id])->assertCreated();
        $password = $this->postJson('/api/admin/users/reset-password', [
            'profile_type' => 'student',
            'profile_id' => $student->id,
        ])->assertOk()->json('data.password');

        $logged = AuditLog::query()->get()->map(fn (AuditLog $log) => json_encode($log->getAttributes(), JSON_UNESCAPED_UNICODE))->implode(' ');

        $this->assertStringNotContainsString($password, $logged);
        $this->assertStringContainsString('reset_password', $logged);
    }

    private function student(): Student
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);

        return Student::create([
            'group_id' => $group->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'phone' => '+79001234567',
            'status' => 'active',
        ]);
    }
}
