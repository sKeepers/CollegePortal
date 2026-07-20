<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_get_token(): void
    {
        $role = Role::create(['name' => 'Administrator', 'code' => 'admin']);
        User::factory()->create([
            'role_id' => $role->id,
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'admin@example.test')
            ->assertJsonPath('user.role.code', 'admin')
            ->assertJsonStructure(['token']);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable();
    }

    public function test_dev_login_helper_is_hidden_until_dev_flag_and_allowed_host(): void
    {
        config([
            'dev_login.enabled' => false,
            'dev_login.allowed_hosts' => ['localhost'],
        ]);

        $this->withServerVariables(['HTTP_HOST' => 'localhost'])
            ->getJson('/api/dev-login/options')
            ->assertNotFound();

        $this->app->detectEnvironment(fn () => 'local');
        config(['dev_login.enabled' => true]);

        $this->withServerVariables(['HTTP_HOST' => 'blocked.test'])
            ->getJson('/api/dev-login/options')
            ->assertNotFound();
    }

    public function test_dev_login_helper_logs_in_by_backend_role_without_credentials(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config([
            'dev_login.enabled' => true,
            'dev_login.allowed_hosts' => ['localhost'],
            'dev_login.roles' => ['admin' => 'Администратор'],
        ]);
        Role::create(['name' => 'Администратор', 'code' => 'admin']);

        $this->withServerVariables(['HTTP_HOST' => 'localhost'])
            ->postJson('/api/dev-login/login', ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.role.code', 'admin')
            ->assertJsonMissingPath('password');
    }
    public function test_protected_api_requires_token(): void
    {
        $this->getJson('/api/groups')
            ->assertUnauthorized();
    }

    public function test_user_can_get_profile_and_logout(): void
    {
        $user = $this->createApiUser();

        $this->withApiAuth($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);

        $this->withApiAuth($user)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'api_token_hash' => null,
        ]);
    }
}
