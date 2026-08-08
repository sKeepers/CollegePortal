<?php

namespace App\Support;

/**
 * Одно место, где решается, какие написания логина означают одного человека.
 *
 * Логином служит email, имя пользователя или телефон, причем телефон люди
 * набирают четырьмя способами. Поиск учетной записи обязан находить человека по
 * любому из них — это уже было сделано. Счетчик попыток входа обязан складывать
 * их в один счет, иначе подбор пароля получает четырехкратный запас попыток
 * просто из-за формы записи номера.
 *
 * Обе задачи опираются на одно правило, поэтому правило живет здесь, а не по
 * копии в контроллере и в ограничителе.
 */
final class LoginIdentifier
{
    /**
     * Все написания, по которым ищем учетную запись.
     *
     * @return list<string>
     */
    public static function variants(string $login): array
    {
        $phone = self::phoneDigits($login);

        if ($phone === null) {
            return [$login];
        }

        return array_values(array_unique([
            $login,
            "+7{$phone}",
            "7{$phone}",
            "8{$phone}",
        ]));
    }

    /**
     * Единственное написание, по которому считаем попытки входа.
     */
    public static function canonical(string $login): string
    {
        $login = trim($login);
        $phone = self::phoneDigits($login);

        return $phone !== null ? "+7{$phone}" : mb_strtolower($login);
    }

    private static function phoneDigits(string $login): ?string
    {
        $digits = preg_replace('/\D+/', '', $login) ?? '';

        return preg_match('/^(?:7|8)(\d{10})$/', $digits, $matches) === 1 ? $matches[1] : null;
    }
}
