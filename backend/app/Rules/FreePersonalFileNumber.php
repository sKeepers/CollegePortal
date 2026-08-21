<?php

namespace App\Rules;

use App\Models\Student;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Номер личного дела занят в пределах своей буквы.
 *
 * Выяснено в учебной части 21.08.2026: **у каждой буквы алфавита своя
 * нумерация**. Поэтому Иванов и Петров могут законно носить один и тот же
 * номер, а два Ивановых — нет. Ключ уникальности здесь пара «первая буква
 * фамилии + номер», а не номер сам по себе: на настоящих списках 2026-2027
 * номер повторяется 108 раз, и все эти повторы правильные.
 *
 * Проверка живёт в приложении, а не в базе, по двум причинам. Первая буква
 * фамилии — величина вычисляемая, и она меняется, когда человек меняет фамилию.
 * Вторая: в данных колледжа конфликты ещё есть (на 21.08.2026 их два), и
 * ограничение в базе не дало бы загрузить контингент вовсе.
 *
 * Отбор по номеру идёт запросом, а сравнение буквы — в памяти: `left()` и
 * `substr()` пишутся по-разному на PostgreSQL и SQLite, а строк с одним номером
 * заведомо единицы.
 */
class FreePersonalFileNumber implements ValidationRule
{
    public function __construct(
        private readonly ?string $lastName,
        private readonly ?int $ignoreStudentId = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $number = trim((string) $value);
        $letter = self::letterOf($this->lastName);

        if ($number === '' || $letter === null) {
            return;
        }

        $taken = Student::query()
            ->where('personal_file_number', $number)
            ->when($this->ignoreStudentId !== null, fn ($query) => $query->whereKeyNot($this->ignoreStudentId))
            ->get(['id', 'last_name', 'first_name', 'middle_name'])
            ->first(fn (Student $student): bool => self::letterOf($student->last_name) === $letter);

        if ($taken === null) {
            return;
        }

        $name = trim(implode(' ', array_filter([$taken->last_name, $taken->first_name, $taken->middle_name])));

        $fail("Номер личного дела {$number} на букву «{$letter}» уже занят: {$name}. У каждой буквы своя нумерация, повторяться номер внутри буквы не может.");
    }

    public static function letterOf(?string $lastName): ?string
    {
        $lastName = trim((string) $lastName);

        return $lastName === '' ? null : mb_strtoupper(mb_substr($lastName, 0, 1));
    }
}
