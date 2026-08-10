<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Проверка ИНН на входе в карточку.
 *
 * Длин две: 10 знаков у организации и 12 у физического лица. У десятизначного один
 * контрольный разряд, у двенадцатизначного два, и второй считается по одиннадцати
 * знакам, включая первый контрольный, — поэтому проверять надо оба, иначе опечатка
 * в предпоследнем знаке пройдёт незамеченной.
 *
 * Пустое значение проходит: в карточке человека это «очистить», в профильной —
 * «не менять». Подробнее — в пояснении к `Snils`.
 */
class Inn implements ValidationRule
{
    /** Веса разрядов, взяты из порядка расчёта контрольного числа ИНН. */
    private const WEIGHTS_10 = [2, 4, 10, 3, 5, 9, 4, 6, 8];

    private const WEIGHTS_11 = [7, 2, 4, 10, 3, 5, 9, 4, 6, 8];

    private const WEIGHTS_12 = [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if ($digits === '') {
            return;
        }

        if (! in_array(strlen($digits), [10, 12], true)) {
            $fail('ИНН должен содержать 10 цифр у организации или 12 у человека.');

            return;
        }

        if (! self::checksumValid($digits)) {
            $fail('Контрольное число ИНН не совпадает. Проверьте, верно ли переписан номер.');
        }
    }

    public static function checksumValid(string $digits): bool
    {
        if (strlen($digits) === 10) {
            return self::controlDigit($digits, self::WEIGHTS_10) === (int) $digits[9];
        }

        if (strlen($digits) !== 12) {
            return false;
        }

        return self::controlDigit($digits, self::WEIGHTS_11) === (int) $digits[10]
            && self::controlDigit($digits, self::WEIGHTS_12) === (int) $digits[11];
    }

    /** @param list<int> $weights */
    private static function controlDigit(string $digits, array $weights): int
    {
        $sum = 0;

        foreach ($weights as $position => $weight) {
            $sum += ((int) $digits[$position]) * $weight;
        }

        return $sum % 11 % 10;
    }
}
