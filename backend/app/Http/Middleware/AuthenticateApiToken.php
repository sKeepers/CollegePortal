<?php

namespace App\Http\Middleware;

use App\Support\Auth\ApiTokenResolver;
use App\Support\Auth\SessionCookie;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function __construct(private readonly ApiTokenResolver $tokens)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Разбор вынесен в общий резолвер: ограничитель частоты спрашивает того же
        // владельца токена раньше по цепочке, и ответ переиспользуется — обращение
        // к базе остаётся одно на запрос.
        $user = $this->tokens->resolve($request);

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        $response = $next($request);

        return $this->renewIfExpiringSoon($request, $response, $user);
    }

    /**
     * Скользящее продление сессии.
     *
     * Раньше продления не было вовсе: токен жил фиксированный срок и человек вылетал
     * посреди работы. Продлеваем, когда израсходовано больше половины срока, — так
     * запись в базу происходит редко, а работающий человек не выпадает.
     *
     * Сам токен не меняется, меняется только срок: значение cookie остаётся прежним,
     * переставляется её время жизни. Смена токена посреди работы разлогинила бы
     * соседние вкладки.
     */
    private function renewIfExpiringSoon(Request $request, Response $response, $user): Response
    {
        if ($this->tokens->source($request) !== ApiTokenResolver::SOURCE_COOKIE) {
            return $response;
        }

        $ttl = (int) config('auth.api_token_ttl_minutes', 720);
        $expiresAt = $user->api_token_expires_at;

        if ($expiresAt === null) {
            return $response;
        }

        $remaining = now()->diffInMinutes($expiresAt, false);

        if ($remaining >= $ttl / 2) {
            return $response;
        }

        $token = $this->tokens->token($request);

        if ($token === null) {
            return $response;
        }

        $user->forceFill(['api_token_expires_at' => now()->addMinutes($ttl)])->save();

        // Сеансовой cookie срок не нужен — браузер держит её до закрытия сам, и
        // переставлять её значило бы превратить её в постоянную.
        if (! SessionCookie::isPersistent($request)) {
            return $response;
        }

        foreach (SessionCookie::issue($request, $token, $ttl) as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
