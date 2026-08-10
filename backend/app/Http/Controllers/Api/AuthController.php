<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Auth\SessionCookie;
use App\Support\LoginIdentifier;
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
        $phoneLogins = LoginIdentifier::variants($login);
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
        $ttl = (int) config('auth.api_token_ttl_minutes', 720);

        $user->forceFill([
            'api_token_hash' => Hash::make($token),
            'api_token_lookup_hash' => hash('sha256', $token),
            'api_token_expires_at' => now()->addMinutes($ttl),
            'last_login_at' => now(),
        ])->save();

        AuditLogService::log('auth', 'login', $user, null, ['login' => $login], $request, $user);

        // Токен уходит в httpOnly cookie и в теле ответа больше не приходит: пока он
        // лежал в хранилище браузера, любая XSS уносила сессию. Рядом ставится читаемый
        // признак CSRF — его фронтенд перекладывает в заголовок изменяющих запросов.
        $response = response()->json([
            'token_type' => 'Cookie',
            'csrf_token' => SessionCookie::csrfValue($token),
            'expires_at' => $user->api_token_expires_at?->toISOString(),
            'user' => new UserResource($user->refresh()->load(['role.permissions', 'roles.permissions', 'student.group', 'teacher'])),
        ]);

        // «Не выходить на этом устройстве» осталось тем же выбором, что и раньше, только
        // теперь это постоянная cookie против сеансовой, а не localStorage против sessionStorage.
        foreach (SessionCookie::issue($request, $token, $ttl, $request->boolean('staySignedIn', true)) as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
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

        // Обнулить запись в базе мало: cookie надо снять, иначе браузер продолжит
        // отправлять уже недействительный токен на каждый запрос.
        $response = response()->json(['message' => 'Logged out.']);

        foreach (SessionCookie::forget() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
