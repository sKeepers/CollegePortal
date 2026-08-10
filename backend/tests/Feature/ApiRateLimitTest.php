<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ограничитель `api.authenticated` обязан считать запросы по человеку, а не по адресу.
 *
 * Снаружи весь колледж приходит через один NAT. Пока счётчик вёлся по адресу, общие
 * 120 запросов в минуту выбирали друг у друга несколько одновременно работающих людей,
 * и портал начинал отдавать 429 всем сразу.
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const LIMIT = 120;

    public function test_two_people_from_one_address_have_independent_counters(): void
    {
        $first = $this->tokenForNewUser();
        $second = $this->tokenForNewUser();

        $this->assertSame(self::LIMIT - 1, $this->remainingAfterRequest('203.0.113.10', $first));
        $this->assertSame(self::LIMIT - 1, $this->remainingAfterRequest('203.0.113.10', $second));
    }

    public function test_one_person_from_two_addresses_shares_a_counter(): void
    {
        $token = $this->tokenForNewUser();

        $this->assertSame(self::LIMIT - 1, $this->remainingAfterRequest('203.0.113.10', $token));
        $this->assertSame(self::LIMIT - 2, $this->remainingAfterRequest('198.51.100.7', $token));
    }

    /**
     * Обратная сторона: ключ нельзя выводить прямо из токена. Иначе перебор случайных
     * значений давал бы каждому запросу собственный счётчик и снимал ограничение вовсе.
     * Неопознанный токен считается по адресу.
     */
    public function test_unrecognised_tokens_from_one_address_share_a_counter(): void
    {
        $this->assertSame(self::LIMIT - 1, $this->remainingAfterRequest('203.0.113.10', Str::random(80), 401));
        $this->assertSame(self::LIMIT - 2, $this->remainingAfterRequest('203.0.113.10', Str::random(80), 401));
    }

    /**
     * Ограничитель и `api.token` спрашивают одного и того же владельца токена.
     * Если бы каждый разбирал сам, на каждый запрос приходилось бы по два одинаковых
     * обращения к базе — цена, которой у этой правки быть не должно.
     */
    public function test_the_token_is_looked_up_once_per_request(): void
    {
        $token = $this->tokenForNewUser();
        $lookups = 0;

        DB::listen(function ($query) use (&$lookups): void {
            if (str_contains($query->sql, 'api_token_lookup_hash')) {
                $lookups++;
            }
        });

        $this->remainingAfterRequest('203.0.113.10', $token);

        $this->assertSame(1, $lookups);
    }

    private function tokenForNewUser(): string
    {
        $token = Str::random(80);
        $this->createApiUser($token);

        return $token;
    }

    private function remainingAfterRequest(string $ip, string $token, int $expectedStatus = 200): int
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus($expectedStatus);

        return (int) $response->headers->get('X-RateLimit-Remaining');
    }
}
