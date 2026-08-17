<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserIdentity;
use App\Support\Auth\Providers\ExternalIdentityProviders;
use App\Support\Auth\Providers\MiniAppLoginProvider;
use App\Support\Auth\Providers\TelegramLoginProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Вход из мини-приложения: портал, открытый внутри Telegram или MAX, входит сам
 * по подписанным данным запуска.
 */
class MiniAppLoginTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:test-bot-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ExternalIdentityProviders::class, new ExternalIdentityProviders([
            new TelegramLoginProvider(self::TOKEN, 'skki_bot'),
            new MiniAppLoginProvider('telegram_miniapp', 'telegram', 'Telegram (мини-приложение)', self::TOKEN),
            new MiniAppLoginProvider('max_miniapp', 'max', 'MAX (мини-приложение)', self::TOKEN),
        ]));
    }

    public function test_a_linked_person_gets_in_from_the_mini_app(): void
    {
        $user = $this->linkedUser('telegram', '4242');

        $this->postJson('/api/auth/provider-login', [
            'provider' => 'telegram_miniapp',
            'payload' => ['init_data' => $this->initData('4242')],
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Cookie')
            ->assertJsonPath('user.id', $user->id);
    }

    /**
     * Привязка одна на мессенджер, а не на способ входа: человек привязывал
     * Telegram виджетом, а входит из мини-приложения — и это тот же человек.
     */
    public function test_the_mini_app_uses_the_same_link_as_the_widget(): void
    {
        $this->linkedUser('telegram', '4242');

        $this->assertSame(
            0,
            UserIdentity::query()->where('provider', 'telegram_miniapp')->count(),
            'Привязка обязана храниться под кодом мессенджера, а не способа входа.',
        );

        $this->postJson('/api/auth/provider-login', [
            'provider' => 'telegram_miniapp',
            'payload' => ['init_data' => $this->initData('4242')],
        ])->assertOk();
    }

    public function test_a_forged_signature_is_refused(): void
    {
        $this->linkedUser('telegram', '4242');

        $forged = preg_replace('/hash=[0-9a-f]+/', 'hash='.str_repeat('a', 64), $this->initData('4242'));

        $this->postJson('/api/auth/provider-login', [
            'provider' => 'telegram_miniapp',
            'payload' => ['init_data' => $forged],
        ])->assertStatus(422);
    }

    /** Подпись верна вечно, поэтому ограничивает только `auth_date`. */
    public function test_stale_launch_data_is_refused(): void
    {
        $this->linkedUser('telegram', '4242');

        $this->postJson('/api/auth/provider-login', [
            'provider' => 'telegram_miniapp',
            'payload' => ['init_data' => $this->initData('4242', now()->subDay()->getTimestamp())],
        ])->assertStatus(422);
    }

    /** Учётная запись не заводится никогда: непривязанный аккаунт не входит. */
    public function test_an_unlinked_account_does_not_get_in(): void
    {
        $this->postJson('/api/auth/provider-login', [
            'provider' => 'telegram_miniapp',
            'payload' => ['init_data' => $this->initData('9999')],
        ])->assertStatus(422);

        $this->assertSame(0, UserIdentity::query()->count());
    }

    /** У MAX своя привязка: телеграмная его не пускает. */
    public function test_max_does_not_ride_on_a_telegram_link(): void
    {
        $this->linkedUser('telegram', '4242');

        $this->postJson('/api/auth/provider-login', [
            'provider' => 'max_miniapp',
            'payload' => ['init_data' => $this->initData('4242')],
        ])->assertStatus(422);

        $this->linkedUser('max', '4242', 'max@example.test');

        $this->postJson('/api/auth/provider-login', [
            'provider' => 'max_miniapp',
            'payload' => ['init_data' => $this->initData('4242')],
        ])->assertOk();
    }

    /**
     * Ключ и сообщение у мини-приложения переставлены местами по сравнению с
     * виджетом. Тест закрепляет именно это: подпись, посчитанная по правилу
     * виджета, приниматься не должна.
     */
    public function test_the_widget_signing_rule_is_not_accepted(): void
    {
        $this->linkedUser('telegram', '4242');

        $params = $this->params('4242', now()->getTimestamp());
        ksort($params);
        $check = implode("\n", array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($params), $params));
        $widgetStyle = hash_hmac('sha256', $check, hash('sha256', self::TOKEN, true));

        $this->postJson('/api/auth/provider-login', [
            'provider' => 'telegram_miniapp',
            'payload' => ['init_data' => http_build_query($params + ['hash' => $widgetStyle])],
        ])->assertStatus(422);
    }

    private function linkedUser(string $provider, string $externalId, string $email = 'mini@example.test'): User
    {
        $user = $this->createApiUser();
        $user->forceFill(['email' => $email])->save();

        UserIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $externalId,
            'linked_at' => now(),
        ]);

        return $user;
    }

    /** @return array<string, string> */
    private function params(string $id, ?int $authDate = null): array
    {
        return [
            'auth_date' => (string) ($authDate ?? now()->getTimestamp()),
            'query_id' => 'AAF',
            'user' => json_encode(['id' => (int) $id, 'first_name' => 'Мария', 'username' => 'maria'], JSON_UNESCAPED_UNICODE),
        ];
    }

    private function initData(string $id, ?int $authDate = null): string
    {
        $params = $this->params($id, $authDate);
        ksort($params);

        $check = implode("\n", array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($params), $params));
        $secret = hash_hmac('sha256', self::TOKEN, 'WebAppData', true);

        return http_build_query($params + ['hash' => hash_hmac('sha256', $check, $secret)]);
    }
}
