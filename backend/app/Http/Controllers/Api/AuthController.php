<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::query()
            ->with('role')
            ->where('email', $credentials['email'])
            ->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'User is inactive.'], Response::HTTP_FORBIDDEN);
        }

        $token = Str::random(80);

        $user->forceFill([
            'api_token_hash' => Hash::make($token),
            'last_login_at' => now(),
        ])->save();

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->refresh()->load('role.permissions')),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('role.permissions'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->forceFill(['api_token_hash' => null])->save();

        return response()->json(['message' => 'Logged out.']);
    }
}
