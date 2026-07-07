<?php

namespace Tests\Feature;

use App\Models\Permission;
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

        $this->withApiAuth()
            ->postJson('/api/admin/users', [
                'name' => 'Тестовый пользователь',
                'email' => 'test.user@example.test',
                'password' => 'demo12345',
                'role_id' => $role->id,
                'is_active' => true,
                'person_type' => 'teacher',
                'person_id' => 10,
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'test.user@example.test')
            ->assertJsonPath('data.person_type', 'teacher')
            ->assertJsonPath('data.status', 'active');

        $user = User::where('email', 'test.user@example.test')->firstOrFail();

        $this->withApiAuth()
            ->putJson("/api/admin/users/{$user->id}", [
                'name' => 'Измененный пользователь',
                'email' => 'test.user@example.test',
                'role_id' => $role->id,
                'is_active' => true,
                'person_type' => 'teacher',
                'person_id' => 11,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Измененный пользователь')
            ->assertJsonPath('data.person_id', 11);

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

    public function test_uat_user_seeder_creates_demo_users_outside_production(): void
    {
        Role::create(['name' => 'Администратор', 'code' => 'admin']);
        Role::create(['name' => 'Учебная часть', 'code' => 'academic_office']);
        Role::create(['name' => 'Преподаватель', 'code' => 'teacher']);
        Role::create(['name' => 'Студент', 'code' => 'student']);
        Role::create(['name' => 'Руководитель', 'code' => 'director']);

        $this->seed(UatUserSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'director.uat@college-portal.local']);
        $this->assertTrue(Hash::check('demo12345', User::where('email', 'student1.uat@college-portal.local')->firstOrFail()->password));
    }
}
