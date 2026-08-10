<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Разбор bearer-токена, общий для проверки доступа и для ограничителя частоты.
 *
 * Ограничителю нужно знать, чей это запрос, раньше, чем срабатывает `api.token`.
 * `ThrottleRequests` стоит в таблице приоритетов Laravel, `AuthenticateApiToken` — нет,
 * и сортировщик выносит ограничитель в начало цепочки. Поэтому `$request->user()`
 * в момент подсчёта пуст, и ключом счётчика всегда оказывался адрес.
 *
 * Класс никого не пускает: он отвечает «чей это токен», а решение о доступе принимает
 * middleware. Неопознанный токен — это `null`, а не отказ.
 *
 * Токен ищется сначала в httpOnly cookie `cp_session`, затем в заголовке
 * `Authorization: Bearer`. Заголовок оставлен для скриптов и внешних вызовов: браузер
 * его сам не подставляет, поэтому CSRF ему не грозит, а из хранилища браузера токен
 * при этом ушёл — ради чего `SEC-002` и делалась.
 *
 * **Ответ запоминается по самому токену, а не «на запрос».** Контейнер переживает
 * отдельный запрос — и в тестах, и под Octane, — поэтому резолвер, запомнивший просто
 * «пользователя», отдал бы следующему запросу предыдущего. Это ровно тот дефект,
 * который здесь и чинится, только этажом ниже. Замер на нынешнем коде показывал именно
 * такую картину: ограничитель видел пользователя от прошлого запроса.
 */
final class ApiTokenResolver
{
    public const SOURCE_COOKIE = 'cookie';

    public const SOURCE_HEADER = 'header';

    private bool $resolved = false;

    private ?string $resolvedToken = null;

    private ?User $user = null;

    private ?string $source = null;

    public function resolve(Request $request): ?User
    {
        $token = $this->tokenFrom($request);

        if ($this->resolved && $this->resolvedToken === $token) {
            return $this->user;
        }

        $this->resolved = true;
        $this->resolvedToken = $token;
        $this->user = $token === null ? null : $this->lookup($token);

        return $this->user;
    }

    /** Сам токен — нужен для сверки признака CSRF и для продления cookie. */
    public function token(Request $request): ?string
    {
        return $this->tokenFrom($request);
    }

    /**
     * Откуда пришёл токен. Различие существенное: cookie браузер подставляет сам,
     * поэтому такой запрос обязан предъявить признак CSRF. Заголовок `Authorization`
     * ставит только тот, кто его написал, — скрипт или внешний вызов, — и подделать
     * его чужим сайтом нельзя.
     */
    public function source(Request $request): ?string
    {
        $this->tokenFrom($request);

        return $this->source;
    }

    private function tokenFrom(Request $request): ?string
    {
        $cookie = $request->cookie(SessionCookie::SESSION);

        if (is_string($cookie) && $cookie !== '') {
            $this->source = self::SOURCE_COOKIE;

            return $cookie;
        }

        $bearer = $request->bearerToken();
        $this->source = $bearer === null ? null : self::SOURCE_HEADER;

        return $bearer;
    }

    private function lookup(string $token): ?User
    {
        return User::query()
            ->where('is_active', true)
            ->where('api_token_lookup_hash', hash('sha256', $token))
            ->whereNotNull('api_token_expires_at')
            ->where('api_token_expires_at', '>', now())
            ->first();
    }
}
