<?php

namespace App\Rules;

use App\Models\Student;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Номер личного дела занят в пределах своей буквы.
 *
 * У каждой буквы алфавита своя нумерация (учебная часть, 21.08.2026). Поэтому
 * Иванов и Петров могут законно носить один и тот же номер, а два дела под
 * буквой «И» с одним номером — нет.
 *
 * **Буква берётся хранимая, а не из текущей фамилии.** Дело заводится один раз,
 * и номер остаётся за человеком при смене фамилии: студентка с номером 115 была
 * Ильясовой, стала Черковой — её дело так и числится по «И». Вычисляй мы букву
 * из фамилии, здесь получился бы ложный конфликт с чужим 115 на «Ч», а сам
 * номер при каждом замужестве менял бы принадлежность.
 *
 * Проверка живёт в приложении, а не индексом в базе: в данных колледжа конфликты
 * ещё встречаются, и жёсткое ограничение не дало бы загрузить контингент.
 */
class FreePersonalFileNumber implements ValidationRule
{
    public function __construct(
        private readonly ?string $letter,
        private readonly ?int $ignoreStudentId = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $number = trim((string) $value);
        $letter = self::normalizeLetter($this->letter);

        if ($number === '' || $letter === null) {
            return;
        }

        $taken = Student::query()
            ->where('personal_file_number', $number)
            ->where('personal_file_letter', $letter)
            ->when($this->ignoreStudentId !== null, fn ($query) => $query->whereKeyNot($this->ignoreStudentId))
            ->first(['id', 'last_name', 'first_name', 'middle_name']);

        if ($taken === null) {
            return;
        }

        $name = trim(implode(' ', array_filter([$taken->last_name, $taken->first_name, $taken->middle_name])));

        $fail("Номер личного дела {$number} по букве «{$letter}» уже занят: {$name}. У каждой буквы своя нумерация, повторяться номер внутри буквы не может.");
    }

    public static function normalizeLetter(?string $letter): ?string
    {
        $letter = trim((string) $letter);

        return $letter === '' ? null : mb_strtoupper(mb_substr($letter, 0, 1));
    }
}
