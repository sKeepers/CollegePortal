<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserIdentity;
use App\Support\Auth\Providers\ExternalIdentityProviders;
use App\Support\Auth\Providers\TelegramLoginProvider;
use App\Support\Auth\SessionCookie;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `AUTH-003`: вход через Telegram поверх слоя `AUTH-005`.
 *
 * Токен бота здесь поддельный, и это правильный способ проверки: подпись Telegram —
 * обычный HMAC-SHA256 по строке проверки данных ключом `SHA256(токен)`, она считается
 * целиком у нас на сервере, обращений к Telegram нет. Настоящий токен ничего бы к
 * проверке не добавил, а в репозитории ему не место.
 *
 * Главное правило слоя проверяется отдельно: **вход через мессенджер не создаёт
 * учётную запись никогда.** Непривязанный аккаунт с безупречной подписью не пускается.
 */
class TelegramLoginTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '8843513132:TEST-TOKEN-NOT-A-REAL-ONE';

    private const BOT_USERNAME = 'skki_portal_test_bot';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->withCredentials();

        config()->set('services.telegram.bot_token', self::BOT_TOKEN);
        config()->set('services.telegram.bot_username', self::BOT_USERNAME);
        $this->app->forgetInstance(ExternalIdentityProviders::class);
    }

    public function test_the_signed_answer_of_the_widget_identifies_the_account(): void
    {
        $identity = $this->provider()->verify($this->signedPayload(['id' => 777, 'username' => 'ivanov']));

        $this->assertNotNull($identity);
        $this->assertSame('777', $identity->providerUserId);
        $this->assertSame('@ivanov', $identity->displayName);
    }

    /** Подделать ответ без токена бота нельзя — на этом всё и держится. */
    public function test_a_tampered_field_breaks_the_signature(): void
    {
        $payload = $this->signedPayload(['id' => 777]);
        $payload['id'] = 778;

        $this->assertNull($this->provider()->verify($payload));
    }

    public function test_a_wrong_bot_token_does_not_verify(): void
    {
        $payload = $this->signedPayload(['id' => 777], 'другой-токен');

        $this->assertNull($this->provider()->verify($payload));
    }

    /**
     * Подпись верна вечно, поэтому ограничивает только `auth_date`: вчерашний ответ
     * виджета — это либо перехваченный, либо поданный повторно.
     */
    public function test_a_stale_answer_is_refused(): void
    {
        $payload = $this->signedPayload(['id' => 777, 'auth_date' => now()->getTimestamp() - 3600]);

        $this->assertNull($this->provider()->verify($payload));
    }

    public function test_an_answer_without_a_hash_is_refused(): void
    {
        $this->assertNull($this->provider()->verify(['id' => 777, 'auth_date' => now()->getTimestamp()]));
    }

    /** Железное правило слоя: вход через мессенджер учётную запись не создаёт. */
    public function test_an_unlinked_account_does_not_get_in_and_no_user_appears(): void
    {
        $before = User::query()->count();

        $this->postJson('/api/auth/provider-login', [
            'provider' => 'telegram',
            'payload' => $this->signedPayload(['id' => 777]),
        ])->assertStatus(422);

        $this->assertSame($before, User::query()->count());
    }

    public function test_a_linked_account_signs_in_and_gets_the_same_cookie_session(): void
    {
        $user = $this->userWithTelegram('777');

        $response = $this->postJson('/api/auth/provider-login', [
            'provider' => 'telegram',
            'payload' => $this->signedPayload(['id' => 777]),
        ]);

        $response->assertOk();
        // Тот же артефакт, что и у входа паролем: токена в теле нет, он в httpOnly cookie.
        $response->assertJsonMissingPath('token');
        $response->assertJsonPath('token_type', 'Cookie');
        $response->assertJsonPath('user.id', $user->id);

        $session = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === SessionCookie::SESSION);
        $this->assertNotNull($session);
        $this->assertTrue($session->isHttpOnly());
        $this->assertNotNull($user->fresh()->api_token_lookup_hash);
    }

    public function test_a_disabled_account_does_not_get_in_through_telegram(): void
    {
        $user = $this->userWithTelegram('777');
        $user->forceFill(['is_active' => false])->save();

        $this->postJson('/api/auth/provider-login', [
            'provider' => 'telegram',
            'payload' => $this->signedPayload(['id' => 777]),
        ])->assertForbidden();
    }

    /**
     * Кнопку рисуют до входа, поэтому список открыт всем. Имя бота в нём есть, токена
     * нет и быть не может — иначе подпись перестала бы что-либо доказывать.
     */
    public function test_the_public_provider_list_carries_the_bot_username_and_no_secret(): void
    {
        $response = $this->getJson('/api/auth/providers');

        $response->assertOk();
        $response->assertJsonPath('data.0.code', 'telegram');
        $response->assertJsonPath('data.0.config.bot_username', self::BOT_USERNAME);
        $this->assertStringNotContainsString(self::BOT_TOKEN, $response->getContent());
    }

    public function test_without_configuration_the_provider_is_not_offered_at_all(): void
    {
        config()->set('services.telegram.bot_token', null);
        $this->app->forgetInstance(ExternalIdentityProviders::class);

        $this->getJson('/api/auth/providers')->assertOk()->assertJsonCount(0, 'data');

        $this->postJson('/api/auth/provider-login', [
            'provider' => 'telegram',
            'payload' => $this->signedPayload(['id' => 777]),
        ])->assertStatus(422);
    }

    private function provider(): TelegramLoginProvider
    {
        return new TelegramLoginProvider(self::BOT_TOKEN, self::BOT_USERNAME);
    }

    /**
     * Ответ виджета, подписанный так же, как его подписывает Telegram: строка проверки
     * данных из отсортированных `ключ=значение`, ключ HMAC — двоичный `SHA256` токена.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function signedPayload(array $overrides = [], ?string $token = null): array
    {
        $payload = array_merge([
            'id' => 777,
            'first_name' => 'Иван',
            'auth_date' => now()->getTimestamp(),
        ], $overrides);

        ksort($payload);
        $checkString = implode("\n", array_map(
            static fn (string $key, mixed $value): string => "{$key}={$value}",
            array_keys($payload),
            $payload,
        ));

        $payload['hash'] = hash_hmac('sha256', $checkString, hash('sha256', $token ?? self::BOT_TOKEN, true));

        return $payload;
    }

    private function userWithTelegram(string $telegramId): User
    {
        $user = $this->createApiUser();
        $user->forceFill(['email' => 'person@local', 'password' => Hash::make('Parol1'), 'is_active' => true])->save();

        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => 'telegram',
            'provider_user_id' => $telegramId,
            'display_name' => '@ivanov',
            'linked_at' => now(),
            'linked_by' => $user->id,
        ]);

        return $user;
    }
}
