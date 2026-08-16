<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\Auth\SessionCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_get_token(): void
    {
        $role = Role::firstOrCreate(['code' => 'admin'], ['name' => 'Administrator']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        // Договор изменился по `SEC-002`: токен уходит в httpOnly cookie и в теле
        // ответа его больше нет. Подробности перехода — в `CookieSessionTest`.
        $this->postJson('/api/auth/login', [
            'login' => 'admin@example.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Cookie')
            ->assertJsonPath('user.email', 'admin@example.test')
            ->assertJsonPath('user.role.code', 'admin')
            ->assertJsonMissingPath('token')
            ->assertCookie(SessionCookie::SESSION)
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
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Неверный email или пароль.');
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
        $this->postJson('/api/auth/login', ['login' => '89990000001', 'password' => 'password'])->assertOk();
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

    /**
     * Счетчик попыток раньше собирался из поля email, которого форма входа не
     * отправляет, поэтому ключ сводился к одному адресу и пятеро ошибившихся
     * закрывали вход всем остальным с того же адреса.
     */
    public function test_wrong_logins_from_one_address_do_not_lock_out_another_account(): void
    {
        $role = Role::firstOrCreate(['code' => 'teacher'], ['name' => 'Преподаватель']);
        User::factory()->create([
            'role_id' => $role->id,
            'username' => 'petrova.av',
            'password' => Hash::make('correct-horse-battery'),
            'is_active' => true,
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/login', ['login' => "somebody{$attempt}", 'password' => 'wrong'])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['login' => 'petrova.av', 'password' => 'correct-horse-battery'])
            ->assertOk();
    }

    /** Подбор пароля к одной учетной записи обязан упираться в счетчик. */
    public function test_repeated_attempts_against_one_account_are_throttled(): void
    {
        $role = Role::firstOrCreate(['code' => 'teacher'], ['name' => 'Преподаватель']);
        User::factory()->create([
            'role_id' => $role->id,
            'username' => 'petrova.av',
            'password' => Hash::make('correct-horse-battery'),
            'is_active' => true,
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/login', ['login' => 'petrova.av', 'password' => "guess{$attempt}"])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['login' => 'petrova.av', 'password' => 'correct-horse-battery'])
            ->assertStatus(429);
    }

    /**
     * Телефон пишут четырьмя способами, и все четыре находят одного человека.
     * Значит, и попытки по ним обязаны складываться в один счет — иначе запас
     * попыток учетверяется просто из-за формы записи номера.
     */
    public function test_phone_login_spellings_share_one_attempt_counter(): void
    {
        $role = Role::firstOrCreate(['code' => 'teacher'], ['name' => 'Преподаватель']);
        User::factory()->create([
            'role_id' => $role->id,
            'username' => '+79990000001',
            'password' => Hash::make('correct-horse-battery'),
            'is_active' => true,
        ]);

        $spellings = ['+79990000001', '79990000001', '89990000001', '8 (999) 000-00-01', '+7 999 000 00 01'];

        foreach ($spellings as $spelling) {
            $this->postJson('/api/auth/login', ['login' => $spelling, 'password' => 'wrong'])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['login' => '+79990000001', 'password' => 'correct-horse-battery'])
            ->assertStatus(429);
    }
}
