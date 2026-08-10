<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Auth\SessionCookie;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `SEC-002`: токен сессии живёт в httpOnly cookie, а не в хранилище браузера.
 *
 * Пока он лежал в `localStorage`, любая XSS уносила сессию. Раз браузер теперь
 * подставляет учётные данные сам, появляется CSRF — от него защищает признак,
 * выведенный из самого токена.
 *
 * Две особенности тестового клиента, на которых легко потерять час.
 *
 * `withUnencryptedCookie`, а не `withCookie`: последний шифрует значение, рассчитывая
 * на middleware расшифровки. У группы `api` его нет и быть не должно — токен и так
 * случайная строка, шифровать её нечем и незачем.
 *
 * `withCredentials()` в `setUp`: JSON-запрос в тестах **не отправляет cookie вовсе**,
 * пока это не включено, — тот же порядок, что в браузере с `credentials: 'include'`,
 * который и выставлен во фронтенде. Без него запрос уходит без cookie, а выглядит это
 * как «приложение не пускает по cookie».
 */
class CookieSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->withCredentials();
    }

    public function test_login_puts_the_token_in_an_http_only_cookie_and_not_in_the_body(): void
    {
        $this->userWithPassword('secret-pass');

        $response = $this->postJson('/api/auth/login', ['login' => 'person@local', 'password' => 'secret-pass']);

        $response->assertOk();
        $response->assertJsonMissingPath('token');

        $session = $this->cookieFrom($response, SessionCookie::SESSION);
        $this->assertNotNull($session, 'Сессионная cookie не поставлена');
        $this->assertTrue($session->isHttpOnly(), 'Токен доступен из JavaScript');
        $this->assertSame('strict', strtolower((string) $session->getSameSite()));

        // Признак CSRF читаемый намеренно: фронтенд кладёт его в заголовок.
        $csrf = $this->cookieFrom($response, SessionCookie::CSRF);
        $this->assertNotNull($csrf);
        $this->assertFalse($csrf->isHttpOnly());
        $this->assertSame(SessionCookie::csrfValue($session->getValue()), $csrf->getValue());
    }

    /**
     * «Не выходить на этом устройстве» снято — cookie обязана быть сеансовой.
     *
     * Сеансовая cookie это отсутствие срока, а не короткий срок: браузер удаляет её сам
     * при закрытии. Владелец сообщил, что Firefox 153 после перезапуска открывает портал
     * авторизованным, — эти два теста отделяют «сервер выдал срок» от «браузер восстановил
     * сеанс». Проверяются все три cookie: если срок появится у `cp_persist`, при следующем
     * продлении сеансовый вход молча станет постоянным.
     */
    public function test_login_without_stay_signed_in_issues_session_cookies_with_no_lifetime(): void
    {
        $this->userWithPassword('secret-pass');

        $response = $this->postJson('/api/auth/login', [
            'login' => 'person@local',
            'password' => 'secret-pass',
            'staySignedIn' => false,
        ]);

        $response->assertOk();

        foreach ([SessionCookie::SESSION, SessionCookie::CSRF, SessionCookie::PERSIST] as $name) {
            $cookie = $this->cookieFrom($response, $name);
            $this->assertNotNull($cookie, "Cookie $name не поставлена");
            $this->assertSame(0, $cookie->getExpiresTime(), "У сеансовой cookie $name появился срок жизни");
        }

        $this->assertSame('0', $this->cookieFrom($response, SessionCookie::PERSIST)?->getValue());
    }

    public function test_login_with_stay_signed_in_issues_cookies_with_a_lifetime(): void
    {
        $this->userWithPassword('secret-pass');

        $response = $this->postJson('/api/auth/login', [
            'login' => 'person@local',
            'password' => 'secret-pass',
            'staySignedIn' => true,
        ]);

        $response->assertOk();

        foreach ([SessionCookie::SESSION, SessionCookie::CSRF, SessionCookie::PERSIST] as $name) {
            $this->assertGreaterThan(now()->getTimestamp(), $this->cookieFrom($response, $name)?->getExpiresTime());
        }
    }

    /**
     * Продление не имеет права превратить сеансовый вход в постоянный.
     *
     * Срок в базе продлевается — человек не выпадает посреди работы, — но cookie не
     * переставляется: переставленная получила бы срок и пережила бы закрытие браузера.
     */
    public function test_renewal_does_not_give_a_session_cookie_a_lifetime(): void
    {
        $user = $this->userWithPassword();
        $token = $this->tokenFor($user);
        $ttl = (int) config('auth.api_token_ttl_minutes', 720);
        $user->forceFill(['api_token_expires_at' => now()->addMinutes(10)])->save();

        $response = $this->withUnencryptedCookie(SessionCookie::SESSION, $token)
            ->withUnencryptedCookie(SessionCookie::PERSIST, '0')
            ->getJson('/api/auth/me');

        $response->assertOk();
        $this->assertTrue($user->fresh()->api_token_expires_at->greaterThan(now()->addMinutes($ttl - 5)));
        $this->assertNull($this->cookieFrom($response, SessionCookie::SESSION), 'Сеансовая cookie переставлена при продлении');
        $this->assertNull($this->cookieFrom($response, SessionCookie::PERSIST));
    }

    public function test_renewal_of_a_persistent_session_moves_the_cookie_lifetime_forward(): void
    {
        $user = $this->userWithPassword();
        $token = $this->tokenFor($user);
        $user->forceFill(['api_token_expires_at' => now()->addMinutes(10)])->save();

        $response = $this->withUnencryptedCookie(SessionCookie::SESSION, $token)
            ->withUnencryptedCookie(SessionCookie::PERSIST, '1')
            ->getJson('/api/auth/me');

        $response->assertOk();
        $this->assertGreaterThan(
            now()->addMinutes(10)->getTimestamp(),
            $this->cookieFrom($response, SessionCookie::SESSION)?->getExpiresTime(),
        );
    }

    public function test_the_session_cookie_authenticates_a_read(): void
    {
        $token = $this->tokenFor($this->userWithPassword());

        $this->withUnencryptedCookie(SessionCookie::SESSION, $token)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_a_change_without_the_csrf_header_is_rejected(): void
    {
        $token = $this->tokenFor($this->userWithPassword());

        $this->withUnencryptedCookie(SessionCookie::SESSION, $token)
            ->postJson('/api/auth/logout')
            ->assertStatus(419)
            ->assertJsonPath('code', 'csrf_token_mismatch');
    }

    public function test_a_change_with_someone_elses_csrf_header_is_rejected(): void
    {
        $token = $this->tokenFor($this->userWithPassword());
        $strangerCsrf = SessionCookie::csrfValue(Str::random(80));

        $this->withUnencryptedCookie(SessionCookie::SESSION, $token)
            ->withHeader(SessionCookie::CSRF_HEADER, $strangerCsrf)
            ->postJson('/api/auth/logout')
            ->assertStatus(419);
    }

    public function test_a_change_with_the_matching_csrf_header_passes_and_logout_clears_the_cookies(): void
    {
        $user = $this->userWithPassword();
        $token = $this->tokenFor($user);

        $response = $this->withUnencryptedCookie(SessionCookie::SESSION, $token)
            ->withHeader(SessionCookie::CSRF_HEADER, SessionCookie::csrfValue($token))
            ->postJson('/api/auth/logout');

        $response->assertOk();
        $this->assertNull($user->fresh()->api_token_lookup_hash);

        // Обнулить запись мало: без снятия cookie браузер продолжит слать мёртвый токен.
        $this->assertSame('', $this->cookieFrom($response, SessionCookie::SESSION)?->getValue());
        $this->assertSame('', $this->cookieFrom($response, SessionCookie::CSRF)?->getValue());
    }

    /**
     * Заголовок `Authorization` остаётся для скриптов и внешних вызовов. Браузер его сам
     * не подставляет, подделать чужим сайтом нельзя — значит и признак CSRF ему не нужен.
     */
    public function test_a_bearer_request_still_works_and_needs_no_csrf_header(): void
    {
        $token = Str::random(80);
        $this->withApiAuth($this->createApiUser($token));

        $this->postJson('/api/auth/logout')->assertOk();
    }

    public function test_an_expired_token_does_not_authenticate(): void
    {
        $user = $this->userWithPassword();
        $token = $this->tokenFor($user);
        $user->forceFill(['api_token_expires_at' => now()->subMinute()])->save();

        $this->withUnencryptedCookie(SessionCookie::SESSION, $token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    /**
     * Скользящее продление: пока человек работает, сессия не должна обрываться посреди дня.
     * Продлеваем, когда израсходовано больше половины срока, — не на каждом запросе.
     */
    public function test_a_session_past_half_its_life_is_renewed(): void
    {
        $user = $this->userWithPassword();
        $token = $this->tokenFor($user);
        $ttl = (int) config('auth.api_token_ttl_minutes', 720);
        $user->forceFill(['api_token_expires_at' => now()->addMinutes(10)])->save();

        $response = $this->withUnencryptedCookie(SessionCookie::SESSION, $token)->getJson('/api/auth/me');

        $response->assertOk();
        $this->assertTrue($user->fresh()->api_token_expires_at->greaterThan(now()->addMinutes($ttl - 5)));
        $this->assertSame($token, $this->cookieFrom($response, SessionCookie::SESSION)?->getValue());
    }

    public function test_a_fresh_session_is_not_renewed_on_every_request(): void
    {
        $user = $this->userWithPassword();
        $token = $this->tokenFor($user);
        $expiresAt = $user->fresh()->api_token_expires_at;

        $this->withUnencryptedCookie(SessionCookie::SESSION, $token)->getJson('/api/auth/me')->assertOk();

        $this->assertTrue($user->fresh()->api_token_expires_at->equalTo($expiresAt));
    }

    private function userWithPassword(string $password = 'secret-pass'): User
    {
        $user = $this->createApiUser();

        $user->forceFill([
            'email' => 'person@local',
            'password' => Hash::make($password),
            'is_active' => true,
        ])->save();

        return $user;
    }

    private function tokenFor(User $user): string
    {
        $token = Str::random(80);

        $user->forceFill([
            'api_token_hash' => Hash::make($token),
            'api_token_lookup_hash' => hash('sha256', $token),
            'api_token_expires_at' => now()->addMinutes((int) config('auth.api_token_ttl_minutes', 720)),
        ])->save();

        return $token;
    }

    private function cookieFrom($response, string $name): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }
}
