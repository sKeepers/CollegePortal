<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Массовая выдача учетных записей на группу. Главное, что здесь проверяется:
 * пароль возвращается ровно один раз и никуда больше не попадает.
 */
class StudentBulkAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Студент', 'code' => 'student']);
        $this->withApiAuth();
    }

    public function test_preview_counts_students_without_accounts_and_creates_nothing(): void
    {
        $group = $this->group();
        $this->student($group, 'Иванов', 'Дмитрий');
        $this->student($group, 'Альгашова', 'Мария');

        $this->postJson('/api/students/bulk/preview', [
            'action' => 'issue_accounts',
            'filter' => ['group_id' => $group->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.selected', 2)
            ->assertJsonPath('data.will_change', 2)
            ->assertJsonPath('data.credentials', []);

        $this->assertSame(0, User::query()->whereNotNull('person_id')->count());
    }

    public function test_apply_issues_accounts_and_returns_each_password_once(): void
    {
        $group = $this->group();
        $first = $this->student($group, 'Иванов', 'Дмитрий', '+79001234567');
        $second = $this->student($group, 'Альгашова', 'Мария');

        $response = $this->postJson('/api/students/bulk/apply', [
            'action' => 'issue_accounts',
            'filter' => ['group_id' => $group->id],
        ])->assertOk();

        $credentials = collect($response->json('data.credentials'));

        $this->assertCount(2, $credentials);
        $this->assertSame(2, $response->json('data.changed'));

        $ivanov = $credentials->firstWhere('id', $first->id);
        $algashova = $credentials->firstWhere('id', $second->id);

        // Логин — телефон в едином написании, а без телефона — фамилия с инициалами.
        $this->assertSame('+79001234567', $ivanov['login']);
        $this->assertSame('algashova.m', $algashova['login']);
        $this->assertSame('Учебная группа', $ivanov['group']);
        $this->assertNotEmpty($ivanov['password']);
        $this->assertNotEmpty($algashova['password']);

        // Пароль в базе только в виде хеша, обратно его взять неоткуда.
        $user = User::query()->where('username', $ivanov['login'])->firstOrFail();
        $this->assertNotSame($ivanov['password'], $user->password);
        $this->assertTrue(password_verify($ivanov['password'], $user->password));

        // Вместе с учетной записью выпускается QR-пропуск.
        $this->assertTrue(
            DigitalIdentity::query()
                ->where('entity_type', DigitalIdentity::ENTITY_STUDENT)
                ->where('entity_id', $first->id)
                ->where('status', DigitalIdentity::STATUS_ACTIVE)
                ->exists()
        );
    }

    public function test_password_never_reaches_the_audit_log(): void
    {
        $group = $this->group();
        $this->student($group, 'Иванов', 'Дмитрий');

        $password = $this->postJson('/api/students/bulk/apply', [
            'action' => 'issue_accounts',
            'filter' => ['group_id' => $group->id],
        ])->assertOk()->json('data.credentials.0.password');

        $this->assertNotEmpty($password);

        $logged = AuditLog::query()->get()->map(fn (AuditLog $log) => json_encode($log->getAttributes(), JSON_UNESCAPED_UNICODE))->implode(' ');

        $this->assertStringNotContainsString($password, $logged);
        // Сам факт выдачи в аудите остается.
        $this->assertStringContainsString('bulk_issue_accounts', $logged);
    }

    public function test_student_with_an_account_is_skipped_not_duplicated(): void
    {
        $group = $this->group();
        $this->student($group, 'Иванов', 'Дмитрий');

        $this->postJson('/api/students/bulk/apply', ['action' => 'issue_accounts', 'filter' => ['group_id' => $group->id]])
            ->assertOk()
            ->assertJsonPath('data.changed', 1);

        $this->postJson('/api/students/bulk/apply', ['action' => 'issue_accounts', 'filter' => ['group_id' => $group->id]])
            ->assertOk()
            ->assertJsonPath('data.changed', 0)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.credentials', []);

        $this->assertSame(1, User::query()->where('name', 'like', 'Иванов%')->count());
    }

    private function group(): Group
    {
        return Group::create([
            'name' => 'Учебная группа',
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);
    }

    private function student(Group $group, string $lastName, string $firstName, ?string $phone = null): Student
    {
        return Student::create([
            'group_id' => $group->id,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'phone' => $phone,
            'status' => 'active',
        ]);
    }
}
