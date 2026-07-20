<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DevLoginHelperController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        if (! $this->isAllowed($request)) {
            return response()->json(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        $roles = collect(config('dev_login.roles', []))
            ->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])
            ->values();

        return response()->json(['data' => $roles]);
    }

    public function login(Request $request): JsonResponse
    {
        if (! $this->isAllowed($request)) {
            return response()->json(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->validate([
            'role' => ['required', 'string', 'max:64'],
        ]);

        $roles = config('dev_login.roles', []);
        $roleCode = $data['role'];

        if (! array_key_exists($roleCode, $roles)) {
            return response()->json(['message' => 'Unknown DEV helper role.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $role = Role::query()->where('code', $roleCode)->first();
        if ($role === null) {
            return response()->json(['message' => 'DEV helper role is not configured.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = User::query()->whereHas('roles', fn ($query) => $query->where('roles.id', $role->id))->first()
            ?? User::query()->where('role_id', $role->id)->first()
            ?? $this->createDevUser($role, $roles[$roleCode]);

        if (! $user->is_active) {
            $user->forceFill(['is_active' => true])->save();
        }

        $token = Str::random(80);
        $user->forceFill([
            'api_token_hash' => Hash::make($token),
            'last_login_at' => now(),
            'must_change_password' => false,
        ])->save();

        AuditLogService::log('auth', 'dev_helper_login', $user, null, [
            'role' => $roleCode,
            'dev_helper' => true,
        ], $request, $user);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->refresh()->load(['role.permissions', 'roles.permissions'])),
        ]);
    }

    private function isAllowed(Request $request): bool
    {
        if (! (bool) config('dev_login.enabled', false)) {
            return false;
        }

        if (! app()->environment(['local', 'development', 'dev'])) {
            return false;
        }

        $allowedHosts = config('dev_login.allowed_hosts', []);
        if ($allowedHosts === []) {
            return false;
        }

        return in_array($request->getHost(), $allowedHosts, true);
    }

    private function createDevUser(Role $role, string $label): User
    {
        $email = 'dev-helper+'.str_replace('_', '-', $role->code).'@college-portal.local';
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'DEV '.$label,
                'password' => Hash::make(Str::random(64)),
                'role_id' => $role->id,
                'is_active' => true,
                'must_change_password' => false,
            ]
        );

        $user->roles()->syncWithoutDetaching([$role->id => ['is_primary' => true]]);
        $user->forceFill(['role_id' => $role->id])->save();

        return $user;
    }
}
