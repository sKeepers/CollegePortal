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
