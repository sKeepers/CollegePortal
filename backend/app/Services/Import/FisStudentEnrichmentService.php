<?php

namespace App\Services\Import;

use App\Models\Person;
use App\Models\Student;
use App\Services\Admissions\IdentityDocumentService;
use App\Services\Admissions\SnilsService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use RuntimeException;

/**
 * Дополнение карточек уже заведённых студентов данными из выгрузки ФИС ГИА.
 *
 * Это не импорт: **новых студентов служба не заводит никогда**. Выгрузки описывают
 * поступавших за четыре года — 847 строк, — а в портале живут 593 сегодняшних
 * студента. Кто отчислился, кто выпустился, кто не дошёл: расхождение неизбежно и
 * потому не считается ошибкой, но каждая несопоставленная строка обязана попасть
 * в отчёт, а не пропасть молча.
 *
 * Три правила записи, все три — решения владельца от 22.08.2026:
 *
 * 1. **Пустое значение в файле не затирает заполненное в портале.** Пустое значит
 *    «нет данных», а не «очистить».
 * 2. **Заполненное в портале тоже не затирается.** Расхождение идёт в отчёт, и
 *    решает его человек: выгрузка ФИС — не более достоверный источник, чем списки
 *    учебной части, по которым карточки заводились.
 * 3. **СНИЛС в учебную карточку пишется всегда, в карточку человека — только если
 *    там пусто.** По СНИЛС человек находится и к нему привязаны документы;
 *    перезапись в Person — это подмена личности, а не уточнение данных.
 *
 * Сопоставление идёт по ФИО и дате рождения: СНИЛС у студентов пуст (их грузили
 * без него), а `applicant_applications` пуста — связать по номеру заявления не с чем.
 */
class FisStudentEnrichmentService
{
    /** Заголовки выгрузки ФИС ГИА, версия столбцов 2023-2026. */
    private const HEADER_MAP = [
        'application_number' => '№ заявления',
        'status' => 'Статус',
        'fio' => 'ФИО',
        'order_name' => 'Наименование приказа',
        'order_number' => 'Номер приказа',
        'passport' => 'Документ, удостоверяющий личность',
        'passport_issuer' => 'Кем выдан документ, удостоверяющий личность',
        'passport_issued_at' => 'Дата выдачи документа, удостоверяющего личность',
        'passport_department_code' => 'Код подразделения, выдавшего документ, удостоверяющий личность',
        'citizenship' => 'Гражданство',
        'gender' => 'Пол',
        'birth_date' => 'Дата рождения',
        'place_birth' => 'Место рождения',
        'region' => 'Регион',
        'settlement_type' => 'Тип населённого пункта',
        'address' => 'Адрес',
        'snils' => 'СНИЛС',
        'email' => 'E-Mail',
    ];

    /** Без этих колонок файл не выгрузка ФИС, и разбирать его нечего. */
    private const REQUIRED_HEADERS = ['ФИО', 'Дата рождения', 'СНИЛС', 'Документ, удостоверяющий личность'];

    /** @var array<int, Student> */
    private array $students = [];

    /** @var array<string, array<string, list<int>>> */
    private array $index = [];

    public function __construct(
        private readonly SnilsService $snils,
        private readonly IdentityDocumentService $identityDocuments,
    ) {
    }

    /**
     * @param  list<array{path: string, label: string, order_date?: string|null}>  $files
     * @param  array<string, int>  $pairs  «файл:строка» => номер карточки студента.
     *                                     Разбирает человек, когда автомат отказался:
     *                                     фамилия сменилась, в дате рождения опечатка.
     * @return array<string, mixed>
     */
    public function enrich(array $files, bool $apply, ?int $limit = null, array $pairs = []): array
    {
        $this->loadStudents();

        $summary = $this->emptySummary();
        $touched = [];
        $seenRows = [];

        foreach ($files as $file) {
            $parsed = $this->parse($file['path']);
            $processed = 0;

            foreach ($parsed as $row) {
                if ($limit !== null && $limit > 0 && $processed >= $limit) {
                    break;
                }
                $processed++;
                $summary['rows_processed']++;

                $context = ['file' => $file['label'], 'row' => $row['_row'], 'subject' => $row['fio']];
                $match = $this->matchByHand($pairs, $file['label'], $row['_row']) ?? $this->match($row);
                $summary[$match['outcome']]++;

                if ($match['student'] === null) {
                    $summary['issues'][] = $context + [
                        'category' => $match['outcome'],
                        'detail' => $match['detail'],
                    ];

                    continue;
                }

                $student = $match['student'];

                // Один человек встречается в двух наборах — поступал повторно или
                // восстанавливался. Вторая строка не ошибка: она дополняет то, что
                // осталось пустым, и ничего не переписывает.
                if (isset($seenRows[$student->id])) {
                    $summary['repeat_rows']++;
                    $summary['issues'][] = $context + [
                        'category' => 'repeat_row',
                        'detail' => 'Студент уже дополнен строкой '.$seenRows[$student->id].'.',
                        'student_id' => $student->id,
                    ];
                }
                $seenRows[$student->id] = $file['label'].':'.$row['_row'];
                $touched[$student->id] = true;

                $this->applyRow($student, $row, $file['order_date'] ?? null, $apply, $summary, $context);
            }

            $summary['files'][] = ['label' => $file['label'], 'rows' => count($parsed), 'processed' => $processed];
            $summary['rows_total'] += count($parsed);
        }

        $summary['students_total'] = count($this->students);
        $summary['students_untouched'] = count($this->students) - count($touched);
        $summary['applied'] = $apply;

        return $summary;
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        return [
            'files' => [],
            'rows_total' => 0,
            'rows_processed' => 0,
            'matched' => 0,
            'matched_without_middle_name' => 0,
            'matched_by_hand' => 0,
            'near_miss' => 0,
            'matched_by_name_only' => 0,
            'ambiguous' => 0,
            'not_found' => 0,
            'repeat_rows' => 0,
            'written' => [],
            'conflicts' => [],
            'issues' => [],
            'students_total' => 0,
            'students_untouched' => 0,
            'applied' => false,
        ];
    }

    private function loadStudents(): void
    {
        $this->students = [];
        $this->index = ['full_bd' => [], 'name_bd' => [], 'full' => []];

        Student::query()
            ->whereNull('archived_at')
            ->with('person')
            ->get()
            ->each(function (Student $student): void {
                $this->students[$student->id] = $student;

                $last = $this->norm($student->last_name);
                $first = $this->norm($student->first_name);
                $middle = $this->norm($student->middle_name);
                $birth = $student->birth_date?->toDateString() ?? '';

                $this->index['full_bd'][$last.'|'.$first.'|'.$middle.'|'.$birth][] = $student->id;
                $this->index['name_bd'][$last.'|'.$first.'|'.$birth][] = $student->id;
                $this->index['full'][$last.'|'.$first.'|'.$middle][] = $student->id;
            });
    }

    /**
     * Пара, назначенная человеком, идёт впереди любого сравнения.
     *
     * Автомат отказывается сопоставлять, когда фамилия в портале и в выгрузке
     * разные или дата рождения расходится, — и правильно делает: угадывание тут
     * дороже пропуска. Но разобрав случай, человек должен иметь возможность
     * сказать «это один и тот же», не внося СНИЛС и паспорт руками.
     *
     * @param  array<string, int>  $pairs
     * @return array{outcome: string, student: Student|null, detail: string}|null
     */
    private function matchByHand(array $pairs, string $label, int $rowNumber): ?array
    {
        $studentId = $pairs[$label.':'.$rowNumber] ?? null;

        if ($studentId === null) {
            return null;
        }

        $student = $this->students[$studentId] ?? null;

        return $student
            ? ['outcome' => 'matched_by_hand', 'student' => $student, 'detail' => 'Пара назначена человеком.']
            : ['outcome' => 'not_found', 'student' => null, 'detail' => 'Карточки '.$studentId.' нет среди действующих студентов.'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{outcome: string, student: Student|null, detail: string}
     */
    private function match(array $row): array
    {
        $last = $this->norm($row['last_name']);
        $first = $this->norm($row['first_name']);
        $middle = $this->norm($row['middle_name']);
        $birth = $row['birth_date'] ?? '';

        if ($last === '' || $first === '') {
            return ['outcome' => 'not_found', 'student' => null, 'detail' => 'В строке нет фамилии или имени.'];
        }

        $candidates = $this->index['full_bd'][$last.'|'.$first.'|'.$middle.'|'.$birth] ?? [];
        if (count($candidates) === 1) {
            return ['outcome' => 'matched', 'student' => $this->students[$candidates[0]], 'detail' => ''];
        }
        if (count($candidates) > 1) {
            return $this->ambiguous($candidates);
        }

        // Отчество в выгрузке и в списках учебной части иногда записано по-разному
        // либо отсутствует. С датой рождения пара «фамилия + имя» остаётся надёжной.
        $candidates = $this->index['name_bd'][$last.'|'.$first.'|'.$birth] ?? [];
        if (count($candidates) === 1) {
            return ['outcome' => 'matched_without_middle_name', 'student' => $this->students[$candidates[0]], 'detail' => ''];
        }
        if (count($candidates) > 1) {
            return $this->ambiguous($candidates);
        }

        // По одному ФИО, без даты рождения, сопоставляем только тех, у кого даты в
        // портале нет вовсе: иначе это уже не уточнение, а угадывание однофамильца.
        $candidates = array_values(array_filter(
            $this->index['full'][$last.'|'.$first.'|'.$middle] ?? [],
            fn (int $id): bool => $this->students[$id]->birth_date === null,
        ));
        if (count($candidates) === 1) {
            return ['outcome' => 'matched_by_name_only', 'student' => $this->students[$candidates[0]], 'detail' => 'Дата рождения в портале не заполнена.'];
        }
        if (count($candidates) > 1) {
            return $this->ambiguous($candidates);
        }

        return $this->nearMiss($last, $first, $birth)
            ?? ['outcome' => 'not_found', 'student' => null, 'detail' => 'Студента с такими ФИО и датой рождения в портале нет.'];
    }

    /**
     * Строка, которая почти сошлась: фамилия разошлась на букву-другую, а имя и
     * дата рождения совпали точно.
     *
     * Такое не сопоставляется автоматически — фамилия слишком дорогая вещь,
     * чтобы угадывать. Но и терять это молча нельзя: без отдельной категории
     * строка уходила в «студента в портале нет» вместе с двумя с половиной
     * сотнями честно чужих, и находили её только вручную. Из четырнадцати
     * карточек, считавшихся безнадёжными, так нашлись три.
     *
     * Применяется решение человека ключом `--pair`.
     *
     * @return array{outcome: string, student: Student|null, detail: string}|null
     */
    private function nearMiss(string $last, string $first, string $birth): ?array
    {
        if ($birth === '') {
            return null;
        }

        foreach ($this->students as $student) {
            if ($this->norm($student->first_name) !== $first) {
                continue;
            }
            if (($student->birth_date?->toDateString() ?? '') !== $birth) {
                continue;
            }

            $distance = $this->distance($last, $this->norm($student->last_name));
            if ($distance > 0 && $distance <= 2) {
                return [
                    'outcome' => 'near_miss',
                    'student' => null,
                    'detail' => 'Имя и дата рождения совпали, фамилия разошлась на '.$distance
                        .': похоже на карточку '.$student->id.'. Применяется ключом --pair.',
                ];
            }
        }

        return null;
    }

    /** Расстояние Левенштейна по символам: встроенный `levenshtein` считает байтами. */
    private function distance(string $a, string $b): int
    {
        $x = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $y = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($x);
        $m = count($y);

        if ($n === 0 || $m === 0) {
            return max($n, $m);
        }

        $previous = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $current = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $current[$j] = min(
                    $previous[$j] + 1,
                    $current[$j - 1] + 1,
                    $previous[$j - 1] + ($x[$i - 1] === $y[$j - 1] ? 0 : 1),
                );
            }
            $previous = $current;
        }

        return $previous[$m];
    }

    /**
     * @param  list<int>  $candidates
     * @return array{outcome: string, student: null, detail: string}
     */
    private function ambiguous(array $candidates): array
    {
        return [
            'outcome' => 'ambiguous',
            'student' => null,
            'detail' => 'Подходит несколько студентов: '.implode(', ', $candidates).'.',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $context
     */
    private function applyRow(Student $student, array $row, ?string $orderDate, bool $apply, array &$summary, array $context): void
    {
        $person = $student->person;
        $context['student_id'] = $student->id;

        $studentChanges = [];

        // СНИЛС. В учебную карточку — всегда, в карточку человека — только в пустое
        // место. Расхождение с тем, что уже записано, идёт в отчёт в обоих случаях.
        $snils = null;
        if ($row['snils_raw'] !== '') {
            try {
                $snils = $this->snils->normalize($row['snils_raw']);
            } catch (ValidationException $exception) {
                $this->issue($summary, $context, 'snils_invalid', $exception->errors()['snils'][0] ?? 'СНИЛС указан некорректно.');
            }
        }

        if ($snils !== null) {
            if (filled($student->snils) && $this->digits($student->snils) !== $this->digits($snils)) {
                $this->conflict($summary, $context, 'snils_student', 'СНИЛС учебной карточки не совпадает с выгрузкой.');
            }
            if ($this->digits($student->snils) !== $this->digits($snils)) {
                $studentChanges['snils'] = $snils;
            }

            $this->writePersonSnils($person, $snils, $apply, $summary, $context);
        }

        // Паспорт. В выгрузке серия и номер лежат одной строкой «9999 999999».
        // Иностранный документ выглядит иначе — «АА 9999999» у гражданина Армении,
        // например, — и в поля российского паспорта не ложится. Дату выдачи и кем
        // выдан пишем только вместе с самим паспортом: одинокая дата выдачи ни к
        // какому документу не относится и в карточке читается как ошибка.
        [$series, $number] = $this->splitPassport($row['passport']);
        if ($row['passport'] !== '' && $series === null) {
            $this->issue($summary, $context, 'passport_unparsed', 'Документ не похож на паспорт РФ — вероятно, иностранный. Реквизиты не записаны.');
        }

        if ($series === null && $row['passport'] !== '') {
            // Учебная карточка знает только реквизиты паспорта РФ, а документ
            // человека — любой вид. Иностранный кладём документом и оставляем
            // поля паспорта пустыми: они не про него.
            $this->writeForeignDocument($person, $row, $apply, $summary);
        }

        if ($series !== null && $number !== null) {
            $this->fillIfEmpty($student, 'passport_series', $series, $studentChanges, $summary, $context);
            $this->fillIfEmpty($student, 'passport_number', $number, $studentChanges, $summary, $context);
            $this->fillIfEmpty($student, 'passport_issue_date', $row['passport_issued_at'], $studentChanges, $summary, $context);
            $this->fillIfEmpty($student, 'passport_issued_by', $row['passport_issuer'], $studentChanges, $summary, $context);
            $this->fillIfEmpty($student, 'passport_department_code', $row['passport_department_code'], $studentChanges, $summary, $context);
        }

        $this->fillIfEmpty($student, 'birth_date', $row['birth_date'], $studentChanges, $summary, $context);
        $this->fillIfEmpty($student, 'address', $row['address'], $studentChanges, $summary, $context);
        $this->fillIfEmpty($student, 'enrollment_order_number', $row['order_number'], $studentChanges, $summary, $context);
        $this->fillIfEmpty($student, 'enrollment_order_date', $orderDate, $studentChanges, $summary, $context);

        if ($studentChanges !== []) {
            foreach (array_keys($studentChanges) as $field) {
                $summary['written']['students.'.$field] = ($summary['written']['students.'.$field] ?? 0) + 1;
            }
            if ($apply) {
                $student->fill($studentChanges)->save();
            }
        }

        $this->writePerson($person, $row, $apply, $summary, $context);
        $this->writePassportDocument($person, $series, $number, $row, $apply, $summary);
    }

    /**
     * Общие поля человека, у которых нет копии в профиле, пишутся прямо в Person.
     *
     * `PersonService::updateSharedData` здесь не годится: он прогоняет через
     * `normalizePersonData` весь набор общих полей, включая СНИЛС, и приводит его к
     * одним цифрам. Записанный рядом форматированный СНИЛС от этого молча менял бы
     * вид при любой правке пола или гражданства. Ни одно из полей ниже в
     * `PROFILE_MIRRORS` не входит, так что раскладывать по профилям нечего.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $context
     */
    private function writePerson(?Person $person, array $row, bool $apply, array &$summary, array $context): void
    {
        if (! $person) {
            $this->issue($summary, $context, 'person_missing', 'У студента нет связанной карточки человека.');

            return;
        }

        $changes = [];
        $this->fillIfEmpty($person, 'gender', $row['gender'], $changes, $summary, $context, 'people');
        $this->fillIfEmpty($person, 'citizenship', $row['citizenship'], $changes, $summary, $context, 'people');
        $this->fillIfEmpty($person, 'place_birth', $row['place_birth'], $changes, $summary, $context, 'people');
        $this->fillIfEmpty($person, 'birth_date', $row['birth_date'], $changes, $summary, $context, 'people');
        $this->fillIfEmpty($person, 'address', $row['address'], $changes, $summary, $context, 'people');

        if ($changes === []) {
            return;
        }

        foreach (array_keys($changes) as $field) {
            $summary['written']['people.'.$field] = ($summary['written']['people.'.$field] ?? 0) + 1;
        }

        if ($apply) {
            $person->fill($changes)->save();
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $context
     */
    private function writePersonSnils(?Person $person, string $snils, bool $apply, array &$summary, array $context): void
    {
        if (! $person) {
            return;
        }

        if (filled($person->snils)) {
            if ($this->digits($person->snils) !== $this->digits($snils)) {
                $this->conflict($summary, $context, 'snils_person', 'СНИЛС карточки человека не совпадает с выгрузкой; в Person не переписан.');
            }

            return;
        }

        $hash = $this->snils->hash($snils);
        $taken = Person::query()->where('snils_hash', $hash)->whereKeyNot($person->id)->exists();

        if ($taken) {
            $this->conflict($summary, $context, 'snils_taken', 'Этот СНИЛС уже стоит у другой карточки человека.');

            return;
        }

        $summary['written']['people.snils'] = ($summary['written']['people.snils'] ?? 0) + 1;

        if ($apply) {
            $this->snils->update($person, $snils);
        }
    }

    /**
     * Паспорт хранится у человека документом — карточка студента только зеркалит
     * реквизиты. Без документа полнота карточки не сходится с приёмной комиссией.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $summary
     */
    private function writePassportDocument(?Person $person, ?string $series, ?string $number, array $row, bool $apply, array &$summary): void
    {
        if (! $person || $series === null || $number === null) {
            return;
        }

        $payload = [
            'series' => $series,
            'number' => $number,
            'issue_date' => $row['passport_issued_at'],
            'issued_by' => $row['passport_issuer'] ?: null,
            'subdivision_code' => $row['passport_department_code'] ?: null,
        ];

        // Считаем только то, что действительно ляжет. Без этой проверки повторный
        // проход отчитывался бы о пяти сотнях «заполненных» паспортов, из которых
        // не менялся ни один, — и отчёт переставал бы что-либо значить.
        if ($this->passportDiffers($person, $payload)) {
            $summary['written']['identity_documents.passport'] = ($summary['written']['identity_documents.passport'] ?? 0) + 1;
        }

        if ($apply) {
            $this->identityDocuments->syncPassportForPerson($person->id, $payload);
        }
    }

    /**
     * @param  array<string, string|null>  $payload
     */
    private function passportDiffers(Person $person, array $payload): bool
    {
        $current = $this->identityDocuments->listForPerson($person->id)->first();

        if (! $current) {
            return true;
        }

        foreach ($payload as $field => $value) {
            if ($value === null) {
                continue;
            }

            $stored = $field === 'issue_date' ? $current->issue_date?->toDateString() : $current->{$field};

            if ((string) $stored !== (string) $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Документ иностранного гражданина: «АС 1234567» и подобное.
     *
     * В поля паспорта РФ он не ложится, но человек без документа остаётся
     * неполным, а пакеты ФИС документ требуют. Кладём его документом вида
     * «Иностранный документ» — справочник такой вид знает.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $summary
     */
    private function writeForeignDocument(?Person $person, array $row, bool $apply, array &$summary): void
    {
        if (! $person) {
            return;
        }

        $parts = preg_split('/\s+/u', trim($row['passport'])) ?: [];
        $number = array_pop($parts);
        $series = implode(' ', $parts) ?: null;

        if ($number === null || $number === '') {
            return;
        }

        $summary['written']['identity_documents.foreign'] = ($summary['written']['identity_documents.foreign'] ?? 0) + 1;

        if ($apply) {
            $this->identityDocuments->syncPassportForPerson($person->id, [
                'series' => $series,
                'number' => $number,
                'issue_date' => $row['passport_issued_at'],
                'issued_by' => $row['passport_issuer'] ?: null,
            ], null, 'foreign_identity');
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $context
     */
    private function fillIfEmpty(object $model, string $field, ?string $value, array &$changes, array &$summary, array $context, string $table = 'students'): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $current = $model->{$field};
        $current = $current instanceof Carbon ? $current->toDateString() : $current;

        if (blank($current)) {
            $changes[$field] = $value;

            return;
        }

        if ($this->norm((string) $current) !== $this->norm($value)) {
            $this->conflict(
                $summary,
                $context,
                $table.'.'.$field,
                'В портале «'.$current.'», в выгрузке «'.$value.'»; портал не переписан.',
            );
        }
    }

    /** @return array{0: string|null, 1: string|null} */
    private function splitPassport(string $value): array
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) !== 10) {
            return [null, null];
        }

        return [substr($digits, 0, 4), substr($digits, 4, 6)];
    }

    /** @param  array<string, mixed>  $summary */
    private function conflict(array &$summary, array $context, string $key, string $detail): void
    {
        $summary['conflicts'][$key] = ($summary['conflicts'][$key] ?? 0) + 1;
        $summary['issues'][] = $context + ['category' => 'conflict:'.$key, 'detail' => $detail];
    }

    /** @param  array<string, mixed>  $summary */
    private function issue(array &$summary, array $context, string $category, string $detail): void
    {
        $summary['issues'][] = $context + ['category' => $category, 'detail' => $detail];
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    private function norm(?string $value): string
    {
        $value = str_replace('ё', 'е', mb_strtolower(trim((string) $value)));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parse(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Файл выгрузки не найден: '.$path);
        }

        $reader = IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(false);
        }
        $sheet = $reader->load($path)->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $headerRow = $this->detectHeaderRow($sheet, $highestRow, $highestColumn);
        $columns = [];
        for ($column = 1; $column <= $highestColumn; $column++) {
            $header = $this->clean($sheet->getCell([$column, $headerRow])->getFormattedValue());
            if ($header !== '') {
                $columns[$header] = $column;
            }
        }

        $missing = array_diff(self::REQUIRED_HEADERS, array_keys($columns));
        if ($missing !== []) {
            throw new RuntimeException('Файл не похож на выгрузку ФИС. Нет колонок: '.implode(', ', $missing));
        }

        $rows = [];
        for ($rowNumber = $headerRow + 1; $rowNumber <= $highestRow; $rowNumber++) {
            $raw = [];
            $hasData = false;
            foreach (self::HEADER_MAP as $field => $header) {
                $column = $columns[$header] ?? null;
                $raw[$field] = $column === null ? '' : $this->clean($sheet->getCell([$column, $rowNumber])->getFormattedValue());
                if ($raw[$field] !== '') {
                    $hasData = true;
                }
            }

            if (! $hasData) {
                continue;
            }

            $rows[] = $this->normalizeRow($raw, $rowNumber);
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $raw
     * @return array<string, mixed>
     */
    private function normalizeRow(array $raw, int $rowNumber): array
    {
        $parts = preg_split('/\s+/u', trim($raw['fio'])) ?: [];

        return [
            '_row' => $rowNumber,
            'fio' => $raw['fio'],
            'last_name' => $parts[0] ?? '',
            'first_name' => $parts[1] ?? '',
            'middle_name' => implode(' ', array_slice($parts, 2)),
            'birth_date' => $this->date($raw['birth_date']),
            'gender' => $this->gender($raw['gender']),
            'citizenship' => $raw['citizenship'],
            'place_birth' => $raw['place_birth'],
            'address' => trim(implode(', ', array_filter([$raw['region'], $raw['settlement_type'], $raw['address']]))),
            'snils_raw' => $raw['snils'],
            'email' => mb_strtolower($raw['email']),
            'passport' => $raw['passport'],
            'passport_issuer' => $raw['passport_issuer'],
            'passport_issued_at' => $this->date($raw['passport_issued_at']),
            'passport_department_code' => $raw['passport_department_code'],
            'order_number' => $raw['order_number'],
            'status' => $raw['status'],
            'application_number' => $raw['application_number'],
        ];
    }

    private function detectHeaderRow(mixed $sheet, int $highestRow, int $highestColumn): int
    {
        $bestRow = 1;
        $bestScore = -1;

        for ($row = 1; $row <= min(20, $highestRow); $row++) {
            $score = 0;
            for ($column = 1; $column <= $highestColumn; $column++) {
                $value = mb_strtolower($this->clean($sheet->getCell([$column, $row])->getFormattedValue()));
                foreach (self::REQUIRED_HEADERS as $header) {
                    if ($value === mb_strtolower($header)) {
                        $score++;
                    }
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow = $row;
            }
        }

        return $bestRow;
    }

    private function clean(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    private function gender(string $value): ?string
    {
        $value = mb_strtolower($value);

        return match (true) {
            str_starts_with($value, 'м') => 'male',
            str_starts_with($value, 'ж') => 'female',
            default => null,
        };
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return SpreadsheetDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                // Не серийная дата Excel — разбираем как текст ниже.
            }
        }

        foreach (['d.m.Y', 'd.m.y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $this->clean($value));
                if ($date) {
                    return $date->toDateString();
                }
            } catch (\Throwable) {
                // Следующий формат.
            }
        }

        return null;
    }
}
