<?php

namespace App\Http\Middleware;

use App\Support\Auth\ApiTokenResolver;
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
        $user = $request->bearerToken() === null ? null : $this->tokens->resolve($request);

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
