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

        $login = $credentials['login'] ?? $credentials['email'];
        $phoneLogins = $this->phoneLogins($login);
        $user = User::query()
            ->with(['role.permissions', 'roles.permissions'])
            ->where(function ($query) use ($login, $phoneLogins): void {
                $query->where('email', $login)
                    ->orWhere('username', $login)
                    ->orWhereIn('username', $phoneLogins)
                    ->orWhereHas('person', fn ($person) => $person->whereIn('phone', $phoneLogins))
                    ->orWhereHas('student', fn ($student) => $student->whereIn('phone', $phoneLogins))
                    ->orWhereHas('teacher', fn ($teacher) => $teacher->whereIn('phone', $phoneLogins));
            })
            ->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Неверный email или пароль.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Учетная запись отключена.'], Response::HTTP_FORBIDDEN);
        }

        $token = Str::random(80);

        $user->forceFill([
            'api_token_hash' => Hash::make($token),
            'api_token_lookup_hash' => hash('sha256', $token),
            'api_token_expires_at' => now()->addMinutes((int) config('auth.api_token_ttl_minutes', 720)),
            'last_login_at' => now(),
        ])->save();

        AuditLogService::log('auth', 'login', $user, null, ['login' => $login], $request, $user);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->refresh()->load(['role.permissions', 'roles.permissions', 'student.group', 'teacher'])),
        ]);
    }

    /**
     * Телефон в системе хранится по-разному: импорт оставляет только цифры,
     * формы сохраняют +7, а люди набирают то через 8, то через +7. Поэтому из
     * введенного номера строятся все написания сразу, иначе человек, заведенный
     * импортом, не может войти по телефону ни в одном формате.
     *
     * @return list<string>
     */
    private function phoneLogins(string $login): array
    {
        $digits = preg_replace('/\D+/', '', $login) ?? '';
        if (! preg_match('/^(?:7|8)(\d{10})$/', $digits, $matches)) {
            return [$login];
        }

        return array_values(array_unique([
            $login,
            "+7{$matches[1]}",
            "7{$matches[1]}",
            "8{$matches[1]}",
        ]));
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['role.permissions', 'roles.permissions', 'student.group', 'teacher']));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        AuditLogService::log('auth', 'logout', $user, null, ['email' => $user->email], $request, $user);
        $user->forceFill([
            'api_token_hash' => null,
            'api_token_lookup_hash' => null,
            'api_token_expires_at' => null,
        ])->save();

        return response()->json(['message' => 'Logged out.']);
    }
}
