<?php

namespace App\Services;

use App\Models\Student;

/**
 * Телефон, прилипший к адресу студента.
 *
 * Контингент грузили 22.08.2026 из документа, где адрес, телефон и школа лежат
 * тремя абзацами одной ячейки. Разбор по абзацам починили, но в 178 карточках
 * телефон остался внутри строки адреса — «…, д. 5 тел.8-9XX-XXX-XX-XX», — и при
 * этом собственное поле телефона у этих студентов пустое. То есть телефон в
 * портале есть, а найти его нельзя: ни поиском, ни выгрузкой, ни рассылкой.
 *
 * Служба переносит его на место и подрезает адрес. Ничего не угадывает:
 *
 * - режет строку только там, где за «тел» сразу идут цифры. Улица Тельмана — а
 *   такая в карточках есть — под правило не попадает;
 * - если телефон в карточке уже записан, адрес не трогает и сообщает о
 *   расхождении: два разных номера должен разобрать человек;
 * - если в хвосте больше одного номера или адрес после подреза становится
 *   огрызком, оставляет как есть и пишет в отчёт;
 * - строку, в которой нет ни одной буквы, а есть один телефон, целиком
 *   считает телефоном: адреса у такого студента не записано вовсе.
 *
 * Телефон пишется в карточку человека и оттуда зеркалится в профили —
 * `PersonService::syncProfiles`. Полный `updateSharedData` здесь звать нельзя: он
 * прогоняет через `normalizePersonData` весь набор общих полей и приводит СНИЛС к
 * одним цифрам, а с 23.08.2026 в карточках лежит форматированный.
 */
class StudentAddressCleanupService
{
    /** «тел», за которым сразу начинается номер. Регистр не важен, «Тельмана» не подходит. */
    private const PHONE_MARKER = '/тел[^\p{L}0-9]{0,4}(?=[+0-9])/iu';

    public function __construct(private readonly PersonService $people)
    {
    }

    /** @return array<string, mixed> */
    public function clean(bool $apply, ?int $limit = null): array
    {
        $summary = [
            'scanned' => 0,
            'phone_in_address' => 0,
            'phone_written' => 0,
            'address_trimmed' => 0,
            'skipped' => 0,
            'issues' => [],
        ];

        $students = Student::query()
            ->whereNull('archived_at')
            ->with('person')
            ->orderBy('id')
            ->get();

        foreach ($students as $student) {
            $summary['scanned']++;

            $split = $this->split((string) $student->address);
            if ($split === null) {
                continue;
            }

            $summary['phone_in_address']++;

            if ($limit !== null && $limit > 0 && $summary['phone_in_address'] > $limit) {
                $summary['phone_in_address']--;
                break;
            }

            [$address, $phone, $problem] = $split;

            if ($problem !== null) {
                $summary['skipped']++;
                $summary['issues'][] = ['student_id' => $student->id, 'category' => $problem, 'detail' => ''];

                continue;
            }

            if (filled($this->digits($student->phone)) && $this->digits($student->phone) !== $phone) {
                $summary['skipped']++;
                $summary['issues'][] = [
                    'student_id' => $student->id,
                    'category' => 'phone_conflict',
                    'detail' => 'В карточке уже стоит другой номер; адрес не тронут.',
                ];

                continue;
            }

            $summary['phone_written']++;
            $summary['address_trimmed']++;

            if (! $apply) {
                continue;
            }

            $student->fill(['address' => $address ?: null])->save();

            if ($person = $student->person) {
                $changes = ['phone' => $phone];
                if ($this->split((string) $person->address) !== null) {
                    $changes['address'] = $address ?: null;
                }
                $person->fill($changes)->save();
                $this->people->syncProfiles($person, ['phone']);
            } else {
                // Человека нет — телефон всё равно должен попасть в карточку студента,
                // иначе он останется только в отрезанном хвосте и пропадёт совсем.
                $student->fill(['phone' => $phone])->save();
            }
        }

        return $summary;
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}|null адрес, телефон, причина отказа
     */
    private function split(string $address): ?array
    {
        if ($address === '') {
            return null;
        }

        // Адрес, в котором нет ни одной буквы, — это не адрес. У двух карточек в
        // этом поле лежит один телефон и больше ничего: адреса о человеке просто
        // не записали, а номер положили в ближайшую графу.
        if (! preg_match('/\p{L}/u', $address)) {
            $digits = $this->phoneDigits($this->digits($address));

            return $digits === null ? null : ['', $digits, null];
        }

        if (! preg_match(self::PHONE_MARKER, $address, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $offset = $match[0][1];
        $head = rtrim(substr($address, 0, $offset), " \t\r\n,;.:-");
        $digits = $this->digits(substr($address, $offset));

        if (strlen($digits) > 11) {
            return [$address, '', 'several_phones'];
        }

        $phone = $this->phoneDigits($digits);

        if ($phone === null) {
            return [$address, '', 'phone_too_short'];
        }

        if (mb_strlen($head) < 10) {
            return [$address, '', 'address_too_short'];
        }

        return [$head, $phone, null];
    }

    /** Одиннадцать цифр или ничего: десятизначный номер дописывается восьмёркой. */
    private function phoneDigits(string $digits): ?string
    {
        if (strlen($digits) === 10) {
            $digits = '8'.$digits;
        }

        return strlen($digits) === 11 ? $digits : null;
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }
}
