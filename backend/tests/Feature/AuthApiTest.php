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
        $user = User::factory()->create([
            'role_id' => $role->id,
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'login' => 'admin@example.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'admin@example.test')
            ->assertJsonPath('user.role.code', 'admin')
            ->assertJsonStructure(['token'])
            ->assertJsonMissingPath('user.api_token_hash')
            ->assertJsonMissingPath('user.api_token_lookup_hash');

        $user->refresh();

        $this->assertNotNull($user->api_token_hash);
        $this->assertNotNull($user->api_token_lookup_hash);
        $this->assertNotNull($user->api_token_expires_at);
        $this->assertTrue($user->api_token_expires_at->greaterThan(now()));
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'login' => 'admin@example.test',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable();
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create([
            'email' => 'limited@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
                ->postJson('/api/auth/login', [
                    'login' => 'limited@example.test',
                    'password' => 'wrong-password',
                ])
                ->assertUnprocessable();
        }

        $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->postJson('/api/auth/login', [
                'login' => 'limited@example.test',
                'password' => 'wrong-password',
            ])
            ->assertTooManyRequests();
    }

    public function test_protected_api_requires_token(): void
    {
        $this->getJson('/api/groups')
            ->assertUnauthorized();
    }

    public function test_user_can_login_by_username_or_linked_person_phone(): void
    {
        $user = User::factory()->create([
            'email' => 'account@example.test',
            'username' => 'account.login',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $person = \App\Models\Person::create(['last_name' => 'Иванов', 'first_name' => 'Иван', 'phone' => '+79990000001']);
        $user->update(['person_id' => $person->id, 'person_type' => 'person']);

        $this->postJson('/api/auth/login', ['login' => 'account.login', 'password' => 'password'])->assertOk();
        $this->postJson('/api/auth/login', ['login' => '+79990000001', 'password' => 'password'])->assertOk();
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
            'api_token_lookup_hash' => null,
            'api_token_expires_at' => null,
        ]);
    }

    public function test_expired_api_token_is_rejected(): void
    {
        $token = 'expired-token';
        $user = $this->createApiUser();
        $user->forceFill([
            'api_token_hash' => Hash::make($token),
            'api_token_lookup_hash' => hash('sha256', $token),
            'api_token_expires_at' => now()->subMinute(),
        ])->save();

        $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_legacy_bcrypt_only_api_token_is_rejected(): void
    {
        $token = 'legacy-token';
        $user = $this->createApiUser();
        $user->forceFill([
            'api_token_hash' => Hash::make($token),
            'api_token_lookup_hash' => null,
            'api_token_expires_at' => now()->addHour(),
        ])->save();

        $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }
}
