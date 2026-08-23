<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\LoginCodeService;
use App\Services\ExternalIdentityService;
use App\Support\Auth\Providers\ExternalIdentityProviders;
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

        // Срок стоит только у пароля, выданного порталом: свой, заведённый
        // человеком, не устаревает никогда. Отказ говорит, что делать дальше —
        // иначе человек решит, что сломался портал, и пойдёт звонить.
        if ($user->password_expires_at !== null && $user->password_expires_at->isPast()) {
            return response()->json([
                'message' => 'Срок выданного пароля истёк. Попросите новый у того, кто заводил вам учётную запись.',
            ], Response::HTTP_FORBIDDEN);
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

        return $this->sessionResponse($request, $user, $token);
    }

    /**
     * Вход через внешний способ — `AUTH-003`. Учётная запись **не создаётся никогда**:
     * если привязки нет, вход не состоится, и это железное правило всего слоя.
     * Пароль здесь не спрашивается: подпись провайдера и есть подтверждение личности,
     * а привязывал её человек, уже вошедший паролем.
     */
    public function loginWithProvider(Request $request, ExternalIdentityService $identities): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:32'],
            'payload' => ['required', 'array'],
        ]);

        $user = $identities->resolveLinkedUser($data['provider'], $data['payload']);

        if ($user === null) {
            AuditLogService::log('auth', 'external_login_refused', ['type' => 'User', 'id' => null], null, [
                'provider' => $data['provider'],
            ], $request);

            // Одно сообщение на оба случая — «подпись не сошлась» и «аккаунт ни к кому
            // не привязан». Разделять их незачем: человеку делать надо одно и то же,
            // а подбирающему разница подсказывает.
            return response()->json([
                'message' => 'Этот аккаунт не открывает вход в портал. Войдите паролем и привяжите его в разделе «Моя учётная запись».',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
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

        AuditLogService::log('auth', 'login', $user, null, ['provider' => $data['provider']], $request, $user);

        return $this->sessionResponse($request, $user, $token);
    }

    /**
     * Запросить код входа — человек ввёл логин и нажал «Получить код».
     *
     * Ответ **одинаков всегда**, что бы ни случилось внутри: логина нет, мессенджер
     * не привязан, диалог с ботом не начат, отправка не удалась. Иначе форма входа
     * становится способом перебирать учётные записи колледжа и заодно узнавать, у
     * кого привязан мессенджер. Что произошло на самом деле, знает журнал.
     */
    public function requestCode(Request $request, LoginCodeService $codes): JsonResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:255']]);

        $codes->request($data['login'], $request->ip());

        return response()->json([
            'message' => 'Если такая учётная запись есть и к ней привязан мессенджер, код отправлен в него.',
            'expires_in' => $codes->ttlSeconds(),
        ]);
    }

    /**
     * Войти по коду. Пароль не спрашивается: подтверждением служит владение
     * привязанным аккаунтом мессенджера, а привязывал его человек, вошедший паролем.
     */
    public function loginWithCode(Request $request, LoginCodeService $codes): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:16'],
        ]);

        $user = $codes->verify($data['login'], $data['code']);

        if ($user === null) {
            AuditLogService::log('auth', 'login_code_refused', ['type' => 'User', 'id' => null], null, [
                'login' => $data['login'],
            ], $request);

            // Одно сообщение на «код не тот», «код просрочен» и «кода не было»:
            // человеку делать надо одно и то же, а подбирающему разница подсказывает.
            return response()->json([
                'message' => 'Код неверен или устарел. Запросите новый.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $token = Str::random(80);
        $ttl = (int) config('auth.api_token_ttl_minutes', 720);

        $user->forceFill([
            'api_token_hash' => Hash::make($token),
            'api_token_lookup_hash' => hash('sha256', $token),
            'api_token_expires_at' => now()->addMinutes($ttl),
            'last_login_at' => now(),
        ])->save();

        AuditLogService::log('auth', 'login', $user, null, ['method' => 'code'], $request, $user);

        return $this->sessionResponse($request, $user, $token);
    }

    /**
     * Ответ на успешный вход — один на все способы. Заводить второй значило бы, что
     * внешний вход однажды разойдётся с обычным: у него окажется свой срок жизни
     * cookie или забудется признак CSRF.
     */
    private function sessionResponse(Request $request, User $user, string $token): JsonResponse
    {
        $ttl = (int) config('auth.api_token_ttl_minutes', 720);

        // Токен уходит в httpOnly cookie и в теле ответа не приходит: пока он лежал
        // в хранилище браузера, любая XSS уносила сессию. Рядом ставится читаемый
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

    /**
     * Какие внешние способы входа подключены. Открыто без входа намеренно: кнопку
     * надо нарисовать на форме входа, то есть до того, как человек опознан. Секрета
     * здесь нет — имя бота видит каждый, кто открыл окно подтверждения Telegram.
     */
    public function providers(ExternalIdentityProviders $providers): JsonResponse
    {
        return response()->json(['data' => $providers->available()]);
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
