<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::query()
            ->with(['role.permissions', 'roles.permissions'])
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

        AuditLogService::log('auth', 'login', $user, null, ['email' => $user->email], $request, $user);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->refresh()->load(['role.permissions', 'roles.permissions', 'student.group', 'teacher'])),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['role.permissions', 'roles.permissions', 'student.group', 'teacher']));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        AuditLogService::log('auth', 'logout', $user, null, ['email' => $user->email], $request, $user);
        $user->forceFill(['api_token_hash' => null])->save();

        return response()->json(['message' => 'Logged out.']);
    }
}
