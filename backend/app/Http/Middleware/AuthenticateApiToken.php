<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $lookupHash = hash('sha256', $token);

        $user = User::query()
            ->where('is_active', true)
            ->where('api_token_lookup_hash', $lookupHash)
            ->whereNotNull('api_token_expires_at')
            ->where('api_token_expires_at', '>', now())
            ->first();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
