<?php

namespace App\Services;

use App\Models\Student;

/**
 * Номер личного дела: буква и следующий свободный номер.
 *
 * У каждой буквы алфавита своя нумерация (учебная часть, 21.08.2026). Буква
 * закрепляется за делом в момент заведения и **дальше не меняется**: студентка
 * с номером 115 была Ильясовой и осталась со своим номером, став Черковой — её
 * дело числится по букве «И», и с чужим 115 на «Ч» не спорит.
 *
 * Отсюда два правила, которые легко перепутать:
 *
 * - при заведении карточки буква берётся из фамилии — это и есть момент
 *   присвоения;
 * - при любой последующей правке буква не пересчитывается, даже если фамилия
 *   изменилась. Переписывать её значило бы переписывать прошлое.
 */
class PersonalFileNumberService
{
    /** Буква, под которой заводится дело человека с такой фамилией. */
    public function letterFor(?string $lastName): ?string
    {
        $lastName = trim((string) $lastName);

        return $lastName === '' ? null : mb_strtoupper(mb_substr($lastName, 0, 1));
    }

    /**
     * Следующий свободный номер в пределах буквы.
     *
     * Номера идут подряд и дыр не заполняют: дырка означает, что дело закрыто
     * или переведено, а не что номер свободен. Поэтому берём наибольший занятый
     * и прибавляем единицу.
     */
    public function nextNumberFor(string $letter): int
    {
        $taken = Student::query()
            ->where('personal_file_letter', $letter)
            ->pluck('personal_file_number')
            ->map(fn ($value): int => (int) preg_replace('/\D+/', '', (string) $value))
            ->filter(fn (int $value): bool => $value > 0);

        return $taken->isEmpty() ? 1 : $taken->max() + 1;
    }

    /**
     * Проставить букву и, если номера нет, выдать следующий свободный.
     *
     * Зовётся при заведении карточки — из наблюдателя, чтобы сработать на любом
     * пути: и на ручном заведении, и на загрузке контингента, и на зачислении
     * из приёмной комиссии. Номер, пришедший из файла, не трогается: там он
     * настоящий, из алфавитной книги колледжа.
     */
    public function assign(Student $student): void
    {
        $letter = $student->personal_file_letter ?: $this->letterFor($student->last_name);

        if ($letter === null) {
            return;
        }

        $student->personal_file_letter = $letter;

        if (blank($student->personal_file_number)) {
            $student->personal_file_number = (string) $this->nextNumberFor($letter);
        }
    }
}
