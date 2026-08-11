<?php

namespace App\Support\Auth\Providers;

/**
 * Вход через Telegram — `AUTH-003`. Реализация контракта `ExternalIdentityProvider`
 * поверх готового слоя `AUTH-005`; своей схемы хранения и своей логики доступа не несёт.
 *
 * Telegram Login Widget возвращает поля `id`, `first_name`, `last_name`, `username`,
 * `photo_url`, `auth_date` и `hash`. Подпись считается так: из всех полей, кроме `hash`,
 * собирается строка проверки данных — `ключ=значение`, отсортированные по ключу и
 * склеенные переводом строки, — и от неё берётся `HMAC-SHA256` **ключом `SHA256(токен бота)`**.
 * Ключ именно такой: сам токен в качестве ключа не годится, это частая ошибка.
 *
 * **Сервер никуда не ходит.** Проверка целиком локальная, обращений к `api.telegram.org`
 * нет и не должно быть: с DEV наружу хода нет, а на PROD лишняя внешняя зависимость на
 * пути входа означала бы, что вход ломается вместе с чужой доступностью.
 *
 * Из ответа берутся ровно два поля — идентификатор и что показать человеку. Ни телефона,
 * ни фотографии: чужие персональные данные из мессенджера порталу хранить незачем.
 */
final class TelegramLoginProvider implements ExternalIdentityProvider
{
    public const CODE = 'telegram';

    /**
     * Сколько ответ виджета считается свежим. Полученный вчера ответ — это либо
     * перехваченный, либо повторно поданный: подпись у него верна вечно, ограничивает
     * только `auth_date`.
     */
    private const MAX_AGE_SECONDS = 300;

    public function __construct(
        private readonly string $botToken,
        private readonly string $botUsername,
    ) {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Telegram';
    }

    /**
     * Что нужно браузеру, чтобы нарисовать кнопку. Секрета здесь нет: имя бота публично,
     * его видит каждый, кто открыл окно подтверждения.
     *
     * @return array<string, string>
     */
    public function publicConfig(): array
    {
        return ['bot_username' => $this->botUsername];
    }

    public function verify(array $payload): ?ExternalIdentity
    {
        $hash = $payload['hash'] ?? null;

        if (! is_string($hash) || $hash === '') {
            return null;
        }

        if (! $this->fresh($payload['auth_date'] ?? null)) {
            return null;
        }

        if (! hash_equals($this->expectedHash($payload), strtolower($hash))) {
            return null;
        }

        $id = $payload['id'] ?? null;

        // Идентификатор у Telegram числовой, но в JSON он может прийти и строкой.
        // Приводим к строке один раз здесь: в базе колонка строковая, и `123` с `"123"`
        // не должны стать двумя разными аккаунтами.
        if (! is_int($id) && ! (is_string($id) && preg_match('/^\d+$/', $id) === 1)) {
            return null;
        }

        return new ExternalIdentity((string) $id, $this->displayName($payload));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function expectedHash(array $payload): string
    {
        unset($payload['hash']);
        ksort($payload);

        $checkString = implode("\n", array_map(
            static fn (string $key, mixed $value): string => $key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value),
            array_keys($payload),
            $payload,
        ));

        // Ключ — двоичный SHA256 от токена бота, а не сам токен.
        return hash_hmac('sha256', $checkString, hash('sha256', $this->botToken, true));
    }

    private function fresh(mixed $authDate): bool
    {
        if (! is_numeric($authDate)) {
            return false;
        }

        $age = now()->getTimestamp() - (int) $authDate;

        // Ответ «из будущего» тоже отвергаем: расхождение часов бывает, но подделка
        // с запасом на сутки вперёд выглядит так же.
        return $age >= -self::MAX_AGE_SECONDS && $age <= self::MAX_AGE_SECONDS;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function displayName(array $payload): ?string
    {
        $username = $payload['username'] ?? null;

        if (is_string($username) && $username !== '') {
            return '@'.$username;
        }

        $name = trim(implode(' ', array_filter([
            is_string($payload['first_name'] ?? null) ? $payload['first_name'] : null,
            is_string($payload['last_name'] ?? null) ? $payload['last_name'] : null,
        ])));

        return $name === '' ? null : $name;
    }
}
