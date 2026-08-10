<?php

namespace App\Support\Auth;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Токен сессии живёт в httpOnly cookie, а не в хранилище браузера.
 *
 * Смысл задачи `SEC-002`: любая XSS уносила сессию, пока токен лежал там, куда
 * дотягивается JavaScript. Из httpOnly cookie его не прочитать.
 *
 * Раз браузер теперь подставляет учётные данные сам, появляется CSRF. Признак
 * защиты — не случайное число рядом, а **вывод из самого токена**:
 * `hash_hmac('sha256', токен, app.key)`. Сервер пересчитывает его из cookie сессии
 * и сравнивает с заголовком. Тот, кто не может прочитать httpOnly cookie, заголовок
 * не подделает; хранить при этом нечего и колонок не добавляется.
 */
final class SessionCookie
{
    public const SESSION = 'cp_session';

    public const CSRF = 'cp_csrf';

    public const CSRF_HEADER = 'X-CSRF-Token';

    /** Признак «не выходить на этом устройстве». Секрета не несёт, нужен при продлении. */
    public const PERSIST = 'cp_persist';

    /**
     * @param bool $persistent «не выходить на этом устройстве». Раньше выбор был между
     *                        `localStorage` и `sessionStorage`; теперь — между постоянной
     *                        и сеансовой cookie. Сеансовая исчезает с закрытием браузера.
     * @return list<Cookie> сессия, признак CSRF и признак постоянства
     */
    public static function issue(Request $request, string $token, int $lifetimeMinutes, bool $persistent = true): array
    {
        // `Secure` ставится по факту защищённого соединения, а не безусловно: установка
        // с `HTTPS_MODE=http` иначе не получила бы cookie вовсе и вход перестал бы работать.
        $secure = $request->isSecure();
        // У сеансовой cookie срока нет вовсе: браузер удалит её сам при закрытии.
        $expires = $persistent ? now()->addMinutes($lifetimeMinutes)->getTimestamp() : 0;

        return [
            // SameSite=Strict: портал и API живут на одном домене, стороннего сценария,
            // которому нужна была бы отправка cookie с чужого сайта, здесь нет.
            Cookie::create(self::SESSION, $token)
                ->withHttpOnly(true)
                ->withSecure($secure)
                ->withSameSite(Cookie::SAMESITE_STRICT)
                ->withPath('/')
                ->withExpires($expires),
            // Этот читается из JavaScript намеренно: фронтенд кладёт его в заголовок.
            // Знание признака ничего не даёт без самой сессии.
            Cookie::create(self::CSRF, self::csrfValue($token))
                ->withHttpOnly(false)
                ->withSecure($secure)
                ->withSameSite(Cookie::SAMESITE_STRICT)
                ->withPath('/')
                ->withExpires($expires),
            Cookie::create(self::PERSIST, $persistent ? '1' : '0')
                ->withHttpOnly(false)
                ->withSecure($secure)
                ->withSameSite(Cookie::SAMESITE_STRICT)
                ->withPath('/')
                ->withExpires($expires),
        ];
    }

    public static function isPersistent(Request $request): bool
    {
        return $request->cookie(self::PERSIST) !== '0';
    }

    /**
     * @return list<Cookie>
     */
    public static function forget(): array
    {
        return array_map(
            static fn (string $name): Cookie => Cookie::create($name)->withValue('')->withPath('/')->withExpires(1),
            [self::SESSION, self::CSRF, self::PERSIST],
        );
    }

    public static function csrfValue(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public static function csrfMatches(string $token, ?string $presented): bool
    {
        return $presented !== null && hash_equals(self::csrfValue($token), $presented);
    }
}
