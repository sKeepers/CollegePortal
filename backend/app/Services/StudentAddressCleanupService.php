<?php

namespace App\Services;

use App\Models\Student;
use App\Support\People\AddressPhone;

/**
 * Телефон, прилипший к адресу студента.
 *
 * Контингент грузили 22.08.2026 из документа, где адрес, телефон и школа лежат
 * тремя абзацами одной ячейки. Разбор по абзацам починили, но в 406 строках из
 * 593 телефон всё равно приехал внутри адреса, а собственная графа телефона у
 * этих студентов пустая. То есть телефон в портале есть, а найти его нельзя: ни
 * поиском, ни выгрузкой, ни рассылкой.
 *
 * Первый проход 22.08 разобрал 175 карточек и остановился: правило искало слово
 * «тел» перед цифрами, а 231 строка написана без него. Правило переехало в
 * `AddressPhone` и теперь видит номер и без маркера — там же записано, что
 * именно оно считает телефоном и почему так узко.
 *
 * Служба ничего не угадывает: неоднозначное она не трогает и складывает в
 * отчёт. Если телефон в карточке уже записан и расходится с найденным, адрес
 * остаётся как был — два разных номера разбирает человек.
 *
 * Телефон пишется в карточку человека и оттуда зеркалится в профили —
 * `PersonService::syncProfiles`. Полный `updateSharedData` здесь звать нельзя: он
 * прогоняет через `normalizePersonData` весь набор общих полей и приводит СНИЛС к
 * одним цифрам, а с 23.08.2026 в карточках лежит форматированный.
 */
class StudentAddressCleanupService
{
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
            'person_address_trimmed' => 0,
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

            $split = AddressPhone::split($student->address);
            if ($split === null) {
                continue;
            }

            $summary['phone_in_address']++;

            if ($limit !== null && $limit > 0 && $summary['phone_in_address'] > $limit) {
                $summary['phone_in_address']--;
                break;
            }

            if (! $split->isClean()) {
                $summary['skipped']++;
                $summary['issues'][] = [
                    'student_id' => $student->id,
                    'category' => $split->problem,
                    'detail' => AddressPhone::PROBLEMS[$split->problem] ?? '',
                ];

                continue;
            }

            if (filled($this->digits($student->phone)) && $this->digits($student->phone) !== $split->phone) {
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

            $person = $student->person;
            // Адрес человека разбирается отдельно от учебного: это две записи, и
            // совпадать они не обязаны. Подрезаем его только если он разобрался
            // начисто — иначе там останется прежняя строка, и это видно в отчёте.
            $personSplit = $person ? AddressPhone::split($person->address) : null;

            if ($personSplit?->isClean()) {
                $summary['person_address_trimmed']++;
            } elseif ($personSplit !== null) {
                $summary['issues'][] = [
                    'student_id' => $student->id,
                    'category' => 'person_address_'.$personSplit->problem,
                    'detail' => 'Адрес человека не подрезан: '.(AddressPhone::PROBLEMS[$personSplit->problem] ?? ''),
                ];
            }

            if (! $apply) {
                continue;
            }

            $student->fill(['address' => $split->address ?: null])->save();

            if ($person) {
                $changes = ['phone' => $split->phone];
                if ($personSplit?->isClean()) {
                    $changes['address'] = $personSplit->address ?: null;
                }
                $person->fill($changes)->save();
                $this->people->syncProfiles($person, ['phone']);
            } else {
                // Человека нет — телефон всё равно должен попасть в карточку студента,
                // иначе он останется только в отрезанном хвосте и пропадёт совсем.
                $student->fill(['phone' => $split->phone])->save();
            }
        }

        return $summary;
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }
}
