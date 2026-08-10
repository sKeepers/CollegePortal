<?php

namespace App\Http\Middleware;

use App\Support\Auth\ApiTokenResolver;
use App\Support\Auth\SessionCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Защита от подделки межсайтовых запросов для сессии в cookie.
 *
 * Пока токен передавался заголовком, CSRF не существовало: чужой сайт не может
 * поставить заголовок за пользователя. Cookie браузер подставляет сам, поэтому
 * изменяющий запрос обязан доказать, что его сделал сам портал.
 *
 * Доказательство — заголовок `X-CSRF-Token`, равный `hash_hmac` от токена сессии.
 * Значение лежит в читаемом cookie `cp_csrf`, фронтенд перекладывает его в заголовок.
 * Чужой сайт cookie прочитать не может: читать чужие cookie ему не даёт сам браузер,
 * а отправлять вслепую бесполезно — заголовок он выставить не сможет.
 *
 * Проверяются только изменяющие методы. Чтение защищено `SameSite=Strict`.
 * Запросы с заголовком `Authorization` не проверяются: их ставит скрипт, а не браузер.
 */
class EnsureCsrfToken
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly ApiTokenResolver $tokens)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        if ($this->tokens->source($request) !== ApiTokenResolver::SOURCE_COOKIE) {
            return $next($request);
        }

        $token = $this->tokens->token($request);

        if ($token === null || ! SessionCookie::csrfMatches($token, $request->header(SessionCookie::CSRF_HEADER))) {
            // 419 — код, которым Laravel отвечает на несовпадение CSRF; в Symfony
            // именованной константы для него нет.
            return response()->json([
                'message' => 'Запрос отклонен: не подтверждено происхождение. Обновите страницу и повторите.',
                'code' => 'csrf_token_mismatch',
            ], 419);
        }

        return $next($request);
    }
}
