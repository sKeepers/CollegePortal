<?php

namespace App\Rules;

use App\Services\Admissions\SnilsService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Проверка СНИЛС на входе в карточку.
 *
 * Контрольное число считает `SnilsService` — второй реализации в проекте быть не должно,
 * иначе две проверки со временем разойдутся и одна начнёт пускать то, что другая не пускает.
 * Здесь только правило формы: сервис бросает исключение, а форме нужен отказ по полю.
 *
 * Пустое значение проходит намеренно. В карточке человека пустое поле значит «очистить»,
 * в профильной — «не менять»; ни то, ни другое не является ошибкой ввода, и проверка не
 * вправе превращать их в отказ.
 */
class Snils implements ValidationRule
{
    public function __construct(private readonly SnilsService $snils)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if ($digits === '') {
            return;
        }

        if (strlen($digits) !== 11) {
            $fail('СНИЛС должен содержать 11 цифр.');

            return;
        }

        if (! $this->snils->checksumValid($digits)) {
            $fail('Контрольное число СНИЛС не совпадает. Проверьте, верно ли переписан номер.');
        }
    }
}
