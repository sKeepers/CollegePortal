<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Person;
use App\Models\Teacher;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\UatUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_block_unblock_and_delete_user(): void
    {
        $role = Role::create(['name' => 'Преподаватель', 'code' => 'teacher']);
        $person = Person::create(['last_name' => 'Иванов', 'first_name' => 'Иван', 'status' => 'active']);

        $this->withApiAuth()
            ->postJson('/api/admin/users', [
                'name' => 'Тестовый пользователь',
                'email' => 'test.user@example.test',
                'password' => 'demo12345',
                'role_id' => $role->id,
                'is_active' => true,
                'person_type' => 'teacher',
                'person_id' => $person->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'test.user@example.test')
            ->assertJsonPath('data.person_type', 'teacher')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonCount(1, 'data.roles');

        $user = User::where('email', 'test.user@example.test')->firstOrFail();

        $this->withApiAuth()
            ->putJson("/api/admin/users/{$user->id}", [
                'name' => 'Измененный пользователь',
                'email' => 'test.user@example.test',
                'role_id' => $role->id,
                'is_active' => true,
                'person_type' => 'teacher',
                'person_id' => $person->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Измененный пользователь')
            ->assertJsonPath('data.person_id', $person->id);

        $this->withApiAuth()
            ->postJson("/api/admin/users/{$user->id}/block")
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.status', 'blocked');

        $this->withApiAuth()
            ->postJson("/api/admin/users/{$user->id}/unblock")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->withApiAuth()
            ->deleteJson("/api/admin/users/{$user->id}")
            ->assertNoContent();
    }

    public function test_user_validation_errors_are_localized(): void
    {
        $role = Role::create(['name' => 'Преподаватель', 'code' => 'teacher']);
        User::factory()->create(['email' => 'duplicate@example.test']);

        $this->withApiAuth()
            ->postJson('/api/admin/users', [
                'email' => 'valid@example.test',
                'password' => 'demo12345',
                'role_id' => $role->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Проверьте заполнение формы.')
            ->assertJsonPath('errors.name.0', 'Введите имя пользователя.');

        $this->withApiAuth()
            ->postJson('/api/admin/users', [
                'name' => 'Нет email',
                'password' => 'demo12345',
                'role_id' => $role->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Введите email.');

        $this->withApiAuth()
            ->postJson('/api/admin/users', [
                'name' => 'Плохой email',
                'email' => 'not-email',
                'password' => 'demo12345',
                'role_id' => $role->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Введите корректный email.');

        $this->withApiAuth()
            ->postJson('/api/admin/users', [
                'name' => 'Дубликат',
                'email' => 'duplicate@example.test',
                'password' => 'demo12345',
                'role_id' => $role->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Пользователь с таким email уже существует.');

        $this->withApiAuth()
            ->postJson('/api/admin/users', [
                'name' => 'Короткий пароль',
                'email' => 'short@example.test',
                'password' => 'short',
                'role_id' => $role->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'Пароль должен содержать не менее 8 символов.');

        $this->withApiAuth()
            ->postJson('/api/admin/users', [
                'name' => 'Без роли',
                'email' => 'norole@example.test',
                'password' => 'demo12345',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.role_id.0', 'Выберите роль.');

        $this->withApiAuth()
            ->postJson('/api/admin/users', [
                'name' => 'Без Person',
                'email' => 'missing-person@example.test',
                'password' => 'demo12345',
                'role_id' => $role->id,
                'person_id' => 999999,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.person_id.0', 'Выбранная личная карточка не найдена.');
    }

    public function test_user_update_allows_current_email_and_blank_password(): void
    {
        $role = Role::create(['name' => 'Преподаватель', 'code' => 'teacher']);
        $user = User::factory()->create([
            'name' => 'Редактируемый пользователь',
            'email' => 'editable@example.test',
            'password' => Hash::make('old-password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
        $oldPassword = $user->password;

        $this->withApiAuth()
            ->patchJson("/api/admin/users/{$user->id}", [
                'name' => 'Новое имя',
                'email' => 'editable@example.test',
                'password' => '',
                'role_id' => $role->id,
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'editable@example.test')
            ->assertJsonPath('data.name', 'Новое имя');

        $this->assertSame($oldPassword, $user->refresh()->password);
    }

    public function test_non_user_manager_cannot_open_users_api(): void
    {
        $role = Role::create(['name' => 'Учебная часть', 'code' => 'academic_office']);
        $permission = Permission::create(['name' => 'Управление справочниками', 'code' => 'manage_dictionaries']);
        $role->permissions()->sync([$permission->id]);
        $user = $this->createApiUser(roleCode: 'academic_office');
        $user->forceFill(['role_id' => $role->id])->save();

        $this->withApiAuth($user)
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_admin_can_provision_profile_and_receive_one_time_credential_card(): void
    {
        Role::create(['name' => 'Преподаватель', 'code' => 'teacher']);
        $teacher = Teacher::create([
            'last_name' => 'Петрова',
            'first_name' => 'Анна',
            'phone' => '+79990000010',
            'is_active' => true,
        ]);

        $response = $this->withApiAuth()
            ->postJson('/api/admin/users/provision', [
                'profile_type' => 'teacher',
                'profile_id' => $teacher->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.login', '+79990000010')
            ->assertJsonPath('data.name', 'Петрова Анна')
            ->assertJsonPath('data.role', 'teacher');

        $password = $response->json('data.password');
        $this->assertMatchesRegularExpression('/^\d{5}$/', $password);

        $user = User::where('username', '+79990000010')->firstOrFail();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertSame($user->id, $teacher->refresh()->user_id);

        $audit = AuditLog::query()->where('action', 'provision')->firstOrFail();
        $this->assertStringNotContainsString($password, json_encode([$audit->old_values, $audit->new_values], JSON_THROW_ON_ERROR));
    }

    public function test_user_without_users_manage_cannot_provision_account(): void
    {
        Role::create(['name' => 'Преподаватель', 'code' => 'teacher']);
        $teacher = Teacher::create(['last_name' => 'Петрова', 'first_name' => 'Анна', 'is_active' => true]);
        $user = $this->createApiUser(roleCode: 'academic_office');

        $this->withApiAuth($user)
            ->postJson('/api/admin/users/provision', ['profile_type' => 'teacher', 'profile_id' => $teacher->id])
            ->assertForbidden();
    }

    public function test_uat_user_seeder_creates_demo_users_outside_production(): void
    {
        Role::create(['name' => 'Администратор', 'code' => 'admin']);
        Role::create(['name' => 'Директор', 'code' => 'director']);
        Role::create(['name' => 'Заместитель директора', 'code' => 'deputy']);
        Role::create(['name' => 'Учебная часть', 'code' => 'study']);
        Role::create(['name' => 'Приемная комиссия', 'code' => 'admission']);
        Role::create(['name' => 'Преподаватель', 'code' => 'teacher']);
        Role::create(['name' => 'Студент', 'code' => 'student']);
        Role::create(['name' => 'Сотрудник проходной', 'code' => 'security']);

        $this->seed(UatUserSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'director.uat@college-portal.local']);
        $this->assertTrue(Hash::check('demo12345', User::where('email', 'student1.uat@college-portal.local')->firstOrFail()->password));
    }
}
