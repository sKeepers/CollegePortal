<?php

namespace App\Support\Auth\Providers;

/**
 * Вход из мини-приложения — Telegram и MAX одной реализацией.
 *
 * Портал, открытый **внутри** мессенджера, получает от него подписанные данные
 * запуска. Проверяются они у обеих площадок одинаково, поэтому класс один, а
 * различаются только код, название и токен бота:
 *
 * 1. из данных запуска берутся все пары, кроме `hash`;
 * 2. они сортируются по ключу и склеиваются в `ключ=значение` через перевод строки;
 * 3. `secret_key = HMAC-SHA256(ключ: "WebAppData", сообщение: токен бота)`;
 * 4. `hash = HMAC-SHA256(ключ: secret_key, сообщение: строка из пункта 2)`.
 *
 * **Ключ и сообщение здесь переставлены местами по сравнению с виджетом входа.**
 * У Login Widget ключ — это `SHA256(токен)`, а у мини-приложения ключ — строка
 * `"WebAppData"`, а сообщением служит сам токен. Перепутать их легко, а ошибка
 * тихая: подпись просто никогда не сойдётся, и это выглядит как «вход сломан».
 *
 * **Сервер никуда не ходит** — проверка целиком локальная, как и у виджета.
 *
 * Привязка хранится под кодом мессенджера (`telegram`, `max`), а не под кодом
 * способа входа: человек привязывает мессенджер один раз, и войти он вправе и
 * виджетом, и из мини-приложения. Отсюда `identityCode()`.
 */
final class MiniAppLoginProvider implements ExternalIdentityProvider
{
    /**
     * Насколько свежими считаются данные запуска. Подпись верна вечно, ограничивает
     * только `auth_date`: вчерашние данные — это либо перехваченные, либо поданные
     * повторно.
     */
    private const MAX_AGE_SECONDS = 900;

    public function __construct(
        private readonly string $code,
        private readonly string $identityCode,
        private readonly string $name,
        private readonly string $botToken,
    ) {
    }

    public function code(): string
    {
        return $this->code;
    }

    public function identityCode(): string
    {
        return $this->identityCode;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Рисовать нечего: кнопки у этого способа нет. Портал, открытый внутри
     * мессенджера, входит сам, а снаружи мини-приложения способ недоступен —
     * данных запуска там просто нет.
     *
     * @return array<string, string>
     */
    public function publicConfig(): array
    {
        return ['mode' => 'mini_app'];
    }

    public function verify(array $payload): ?ExternalIdentity
    {
        $params = $this->params($payload);

        if ($params === null) {
            return null;
        }

        $hash = $params['hash'] ?? null;
        unset($params['hash']);

        if (! is_string($hash) || $hash === '' || $params === []) {
            return null;
        }

        if (! $this->fresh($params['auth_date'] ?? null)) {
            return null;
        }

        if (! hash_equals($this->expectedHash($params), strtolower($hash))) {
            return null;
        }

        return $this->identityFrom($params);
    }

    /**
     * Данные запуска приходят строкой запроса — как их отдаёт `initData`. Массив
     * тоже принимаем: разобрать строку мог и вызывающий, а требовать одну форму
     * значило бы, что вторая молча не работает.
     *
     * @param array<string, mixed> $payload
     * @return array<string, string>|null
     */
    private function params(array $payload): ?array
    {
        $raw = $payload['init_data'] ?? $payload['initData'] ?? null;

        if (is_string($raw) && $raw !== '') {
            parse_str($raw, $parsed);

            // Значения обязаны быть скалярными: `a[]=1` превратил бы пару в массив,
            // и строка проверки собралась бы не той, что подписывал мессенджер.
            foreach ($parsed as $value) {
                if (! is_string($value)) {
                    return null;
                }
            }

            return $parsed;
        }

        if (is_array($raw)) {
            $params = [];
            foreach ($raw as $key => $value) {
                if (! is_string($value) && ! is_int($value)) {
                    return null;
                }
                $params[(string) $key] = (string) $value;
            }

            return $params;
        }

        return null;
    }

    /**
     * @param array<string, string> $params
     */
    private function expectedHash(array $params): string
    {
        ksort($params);

        $checkString = implode("\n", array_map(
            static fn (string $key, string $value): string => $key.'='.$value,
            array_keys($params),
            $params,
        ));

        // Ключ — строка «WebAppData», сообщение — токен бота. Не наоборот.
        $secret = hash_hmac('sha256', $this->botToken, 'WebAppData', true);

        return hash_hmac('sha256', $checkString, $secret);
    }

    private function fresh(mixed $authDate): bool
    {
        if (! is_numeric($authDate)) {
            return false;
        }

        $age = now()->getTimestamp() - (int) $authDate;

        // Данные «из будущего» тоже отвергаем: часы расходятся, но подделка с
        // запасом вперёд выглядит так же.
        return $age >= -self::MAX_AGE_SECONDS && $age <= self::MAX_AGE_SECONDS;
    }

    /**
     * Из данных запуска берём ровно два поля — идентификатор и что показать
     * человеку. Ни телефона, ни фотографии: чужие персональные данные из
     * мессенджера порталу хранить незачем.
     *
     * @param array<string, string> $params
     */
    private function identityFrom(array $params): ?ExternalIdentity
    {
        $user = json_decode((string) ($params['user'] ?? ''), true);

        if (! is_array($user)) {
            return null;
        }

        $id = $user['id'] ?? null;

        // Идентификатор числовой, но в JSON приходит и строкой. Приводим один раз
        // здесь: `123` и `"123"` не должны стать двумя разными привязками.
        if (! is_int($id) && ! (is_string($id) && preg_match('/^\d+$/', $id) === 1)) {
            return null;
        }

        return new ExternalIdentity((string) $id, $this->displayName($user));
    }

    /**
     * @param array<string, mixed> $user
     */
    private function displayName(array $user): ?string
    {
        $username = $user['username'] ?? null;

        if (is_string($username) && $username !== '') {
            return '@'.$username;
        }

        $name = trim(implode(' ', array_filter([
            is_string($user['first_name'] ?? null) ? $user['first_name'] : null,
            is_string($user['last_name'] ?? null) ? $user['last_name'] : null,
        ])));

        return $name === '' ? null : $name;
    }
}
