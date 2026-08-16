<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PortalUserSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRoleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_and_delete_role(): void
    {
        $this->withApiAuth()
            ->postJson('/api/admin/roles', [
                'name' => 'Тестовая роль',
                'code' => 'test_role',
                'description' => 'Описание роли',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'test_role');

        $role = Role::where('code', 'test_role')->firstOrFail();

        $this->withApiAuth()
            ->putJson("/api/admin/roles/{$role->id}", [
                'name' => 'Измененная роль',
                'code' => 'test_role',
                'description' => 'Новое описание',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Измененная роль');

        $this->withApiAuth()
            ->deleteJson("/api/admin/roles/{$role->id}")
            ->assertNoContent();
    }

    public function test_role_assigned_to_user_cannot_be_deleted(): void
    {
        $role = Role::firstOrCreate(['code' => 'assigned_role'], ['name' => 'Назначенная роль']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $user->roles()->sync([$role->id => ['is_primary' => true]]);

        $this->withApiAuth()
            ->deleteJson("/api/admin/roles/{$role->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Нельзя удалить роль, назначенную пользователям.');
    }

    public function test_admin_can_assign_multiple_roles_to_user(): void
    {
        $primary = Role::firstOrCreate(['code' => 'primary_role'], ['name' => 'Основная роль']);
        $extra = Role::firstOrCreate(['code' => 'extra_role'], ['name' => 'Дополнительная роль']);
        $user = User::factory()->create(['role_id' => $primary->id]);

        $this->withApiAuth()
            ->postJson("/api/admin/users/{$user->id}/roles", [
                'role_ids' => [$primary->id, $extra->id],
                'primary_role_id' => $extra->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.role_id', $extra->id)
            ->assertJsonCount(2, 'data.roles');

        $this->assertDatabaseHas('role_user', ['user_id' => $user->id, 'role_id' => $extra->id, 'is_primary' => true]);
    }

    public function test_seeders_create_required_roles_and_assignments(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PortalUserSeeder::class);

        foreach (['admin', 'director', 'deputy', 'study', 'study_records', 'admission', 'hr', 'teacher', 'student', 'security'] as $code) {
            $this->assertDatabaseHas('roles', ['code' => $code]);
            $this->assertDatabaseHas('users', ['role_id' => Role::where('code', $code)->value('id')]);
        }

        $security = User::where('email', 'security@local')->firstOrFail();
        $this->assertTrue($security->roles()->where('code', 'security')->exists());
        $this->assertSame('security', $security->role()->first()?->code);
    }
}
