<?php

namespace Tests\Feature;

use App\Models\LoginCode;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Auth\LoginCodeService;
use App\Support\Notifications\NotificationChannel;
use App\Support\Notifications\NotificationChannels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Вход по коду из бота: человек вводит логин, получает шесть цифр в мессенджер
 * и входит без пароля.
 */
class LoginCodeTest extends TestCase
{
    use RefreshDatabase;

    private FakeChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = new FakeChannel();
        $this->app->instance(NotificationChannels::class, new NotificationChannels([$this->channel]));
    }

    public function test_it_sends_a_code_and_lets_the_person_in(): void
    {
        $user = $this->userWithBot();

        $this->postJson('/api/auth/code/request', ['login' => $user->email])
            ->assertOk()
            ->assertJsonPath('expires_in', LoginCodeService::TTL_SECONDS);

        $this->assertNotNull($this->channel->lastText);
        preg_match('/\b(\d{6})\b/', $this->channel->lastText, $matches);
        $this->assertNotEmpty($matches, 'В сообщении нет шестизначного кода.');

        $this->postJson('/api/auth/code/login', ['login' => $user->email, 'code' => $matches[1]])
            ->assertOk()
            ->assertJsonPath('token_type', 'Cookie');
    }

    /**
     * Разницы между «логина нет», «мессенджер не привязан» и «отправить не вышло»
     * снаружи быть не должно: иначе форма входа перебирает учётные записи.
     */
    public function test_the_answer_is_the_same_for_a_login_that_does_not_exist(): void
    {
        $known = $this->postJson('/api/auth/code/request', ['login' => $this->userWithBot()->email]);
        $unknown = $this->postJson('/api/auth/code/request', ['login' => 'nobody@example.test']);

        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    public function test_a_person_without_a_started_chat_gets_no_code(): void
    {
        $user = $this->userWithBot();
        UserIdentity::query()->where('user_id', $user->id)->update(['chat_id' => null]);

        $this->postJson('/api/auth/code/request', ['login' => $user->email])->assertOk();

        $this->assertNull($this->channel->lastText, 'Бот не может написать первым — писать было некуда.');
        $this->assertSame(0, LoginCode::query()->count());
    }

    public function test_a_wrong_code_is_refused_and_burns_after_five_tries(): void
    {
        $user = $this->userWithBot();
        $this->postJson('/api/auth/code/request', ['login' => $user->email])->assertOk();

        for ($try = 1; $try <= LoginCodeService::MAX_ATTEMPTS; $try++) {
            $this->postJson('/api/auth/code/login', ['login' => $user->email, 'code' => '000000'])
                ->assertStatus(422);
        }

        $this->assertNotNull(LoginCode::query()->latest('id')->first()->consumed_at, 'Код обязан сгореть.');
    }

    public function test_an_expired_code_does_not_work(): void
    {
        $user = $this->userWithBot();
        $this->postJson('/api/auth/code/request', ['login' => $user->email])->assertOk();
        preg_match('/\b(\d{6})\b/', (string) $this->channel->lastText, $matches);

        LoginCode::query()->latest('id')->first()->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/auth/code/login', ['login' => $user->email, 'code' => $matches[1]])
            ->assertStatus(422);
    }

    /** Один и тот же код второй раз не пускает: он одноразовый. */
    public function test_a_code_works_only_once(): void
    {
        $user = $this->userWithBot();
        $this->postJson('/api/auth/code/request', ['login' => $user->email])->assertOk();
        preg_match('/\b(\d{6})\b/', (string) $this->channel->lastText, $matches);

        $this->postJson('/api/auth/code/login', ['login' => $user->email, 'code' => $matches[1]])->assertOk();
        $this->postJson('/api/auth/code/login', ['login' => $user->email, 'code' => $matches[1]])->assertStatus(422);
    }

    /** Новый запрос гасит прежний код: двух живых кодов быть не должно. */
    public function test_requesting_again_kills_the_previous_code(): void
    {
        $user = $this->userWithBot();

        $this->postJson('/api/auth/code/request', ['login' => $user->email])->assertOk();
        preg_match('/\b(\d{6})\b/', (string) $this->channel->lastText, $first);

        $this->postJson('/api/auth/code/request', ['login' => $user->email])->assertOk();

        $this->postJson('/api/auth/code/login', ['login' => $user->email, 'code' => $first[1]])
            ->assertStatus(422);
    }

    public function test_a_disabled_account_gets_no_code(): void
    {
        $user = $this->userWithBot();
        $user->forceFill(['is_active' => false])->save();

        $this->postJson('/api/auth/code/request', ['login' => $user->email])->assertOk();

        $this->assertNull($this->channel->lastText);
    }

    private function userWithBot(): User
    {
        $user = $this->createApiUser();
        $user->forceFill(['email' => 'code@example.test', 'password' => Hash::make('Skki-Demo-2026')])->save();

        UserIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'fake',
            'provider_user_id' => '1',
            'chat_id' => '555',
            'chat_started_at' => now(),
            'linked_at' => now(),
        ]);

        return $user;
    }
}

/** Канал, который никуда не ходит и запоминает последний текст. */
class FakeChannel implements NotificationChannel
{
    public ?string $lastText = null;

    public ?string $lastChatId = null;

    public function code(): string
    {
        return 'fake';
    }

    public function name(): string
    {
        return 'Тестовый канал';
    }

    public function send(string $chatId, string $text): bool
    {
        $this->lastChatId = $chatId;
        $this->lastText = $text;

        return true;
    }
}
