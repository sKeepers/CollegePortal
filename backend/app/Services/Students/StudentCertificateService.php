<?php

namespace App\Services\Students;

use App\Models\Student;
use App\Models\StudentCertificate;
use App\Services\SettingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Выдача справок студентам и их реестр.
 *
 * Колледж ведёт реестр справок на бумаге и печатает бланк по двум образцам:
 * первый курс и второй-четвёртый. Владелец принёс оба вместе с реестром
 * 28.08.2026, и здесь повторяется его учёт, а не придумывается свой.
 *
 * **Две справки на студента, и это два разных номера, а не две копии одного.**
 * В реестре под каждого студента отведены графы «Справка 1» и «Справка 2», в
 * обоих образцах на листе по две штуки с соседними номерами. Владелец
 * подтвердил словами: «обычно делают две копии справки с разным номером,
 * следующий студент будет две справки с номерами 1910 и 1911». Счётчик,
 * значит, двигается на два за студента.
 *
 * **Нумерация сплошная, и это проверяемое свойство, а не пожелание.** В файле
 * владельца 1181 номер с 729 по 1909 — пропусков ноль, повторов ноль.
 * Уникальность держит индекс в базе, сплошность — выдача одним куском под
 * замком строки настройки: два оператора, нажавшие «Выдать» одновременно, не
 * получат один номер и не оставят дыру.
 *
 * **В бланк не подставляется пустое.** Чего нет в карточке — то отказ с именем
 * поля, а не пробел в подписанном документе. Курс, дата рождения, приказ о
 * зачислении с датой, специальность и форма обучения обязательны все.
 */
class StudentCertificateService
{
    /** Сколько справок печатают студенту за раз. */
    public const COPIES = 2;

    /** Больше пяти за раз — это не выдача, а опечатка в поле. */
    public const MAX_COPIES = 5;

    private const GROUP = 'certificates';

    /**
     * С какого номера портал продолжает бумажный реестр.
     *
     * Решение владельца от 28.08.2026: «Справки по студентам — 1910». В его
     * файле последний занятый номер 1909, и пропусков в 1181 номере нет ни
     * одного — сплошность колледж держит, и ломать её нам нельзя. Значение
     * живёт в настройках; здесь оно только на случай, если строку настройки
     * ещё не завели.
     */
    private const FIRST_NUMBER = 1910;

    /**
     * Выдать справки студенту.
     *
     * @return Collection<int, StudentCertificate>
     */
    public function issue(Student $student, int $copies = self::COPIES, ?int $userId = null, ?string $issuedOn = null): Collection
    {
        if ($copies < 1 || $copies > self::MAX_COPIES) {
            throw ValidationException::withMessages([
                'copies' => sprintf('За раз выдаётся от 1 до %d справок, а запрошено %d.', self::MAX_COPIES, $copies),
            ]);
        }

        $snapshot = $this->snapshot($student);
        $issuedOn = $issuedOn ?: now()->toDateString();

        return DB::transaction(function () use ($student, $snapshot, $copies, $userId, $issuedOn): Collection {
            $numbers = $this->reserve($copies);

            return collect($numbers)->map(fn (int $number): StudentCertificate => StudentCertificate::create(
                $snapshot + [
                    // Выдано порталом: снимок полон, и это отличает строку от
                    // перенесённой из бумажного реестра, где половины полей нет.
                    'source' => StudentCertificate::SOURCE_PORTAL,
                    'student_id' => $student->id,
                    'number' => $number,
                    'issued_on' => $issuedOn,
                    'issued_by_user_id' => $userId,
                ],
            ));
        });
    }

    /** Отметить, что справку забрали. Пустая дата снимает отметку. */
    public function markReceived(StudentCertificate $certificate, ?string $receivedOn): StudentCertificate
    {
        $certificate->fill(['received_on' => $receivedOn ?: null])->save();

        return $certificate;
    }

    /**
     * Строки реестра за год выдачи.
     *
     * @return Collection<int, StudentCertificate>
     */
    public function registry(?int $year = null, ?int $groupId = null, ?int $number = null): Collection
    {
        return StudentCertificate::query()
            ->with(['student.group', 'issuedBy'])
            // Поиск по номеру — то, ради чего владелец просил реестр: «найти по
            // номеру, кому и когда выдавалась эта справка». Он идёт первым и
            // отменяет остальные отборы: номер единственен, и если его нашли,
            // год и группа только мешают.
            ->when($number !== null, fn ($query) => $query->where('number', $number))
            ->when($number === null && $year !== null, fn ($query) => $query->whereYear('issued_on', $year))
            ->when($number === null && $groupId !== null, fn ($query) => $query->whereHas('student', fn ($s) => $s->where('group_id', $groupId)))
            ->orderBy('number')
            ->get();
    }

    /**
     * Годы, за которые в реестре есть строки. Экран строит из них выбор, а не
     * гадает по диапазону.
     *
     * @return list<int>
     */
    public function years(): array
    {
        // Год считается в PHP, а не запросом: `strftime` и `extract` — разные
        // функции у SQLite и PostgreSQL, и запрос, написанный под один движок,
        // краснеет на другом. Строк реестра тысячи, а не миллионы.
        return StudentCertificate::query()
            ->pluck('issued_on')
            ->filter()
            ->map(fn ($date): int => (int) Carbon::parse($date)->year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * Занять подряд идущие номера.
     *
     * Под замком строки настройки: `lockForUpdate` держит её до конца
     * транзакции, поэтому второй оператор ждёт, а не берёт те же номера. На
     * SQLite замок ничего не делает, и это не беда — там прогон в один поток.
     *
     * Отсчёт ведётся от **большего из двух**: настройки и уже выданного номера.
     * Настройку человек может понизить по ошибке, а выданный номер уже на
     * бумаге у студента, и повторить его нельзя.
     *
     * @return list<int>
     */
    private function reserve(int $count): array
    {
        // Сначала читаем через службу настроек: если строки ещё нет, она заведёт
        // умолчания. Держать замок было бы не на чем.
        $configured = (int) SettingService::value(self::GROUP, 'next_number', self::FIRST_NUMBER);

        $locked = DB::table('settings')
            ->where('group', self::GROUP)
            ->where('key', 'next_number')
            ->lockForUpdate()
            ->first();

        $fromSetting = $locked === null
            ? $configured
            : (int) json_decode((string) $locked->value, true);

        if ($fromSetting < 1) {
            $fromSetting = self::FIRST_NUMBER;
        }

        $issued = (int) StudentCertificate::query()->max('number');
        $start = max($fromSetting, $issued + 1);
        $numbers = range($start, $start + $count - 1);

        DB::table('settings')
            ->where('group', self::GROUP)
            ->where('key', 'next_number')
            ->update(['value' => json_encode($start + $count), 'updated_at' => now()]);

        return $numbers;
    }

    /**
     * Снимок того, что будет напечатано.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Student $student): array
    {
        $student->loadMissing('group.educationProgram.specialty');

        $group = $student->group;
        $program = $group?->educationProgram;

        $missing = [];

        $fullName = trim(implode(' ', array_filter([$student->last_name, $student->first_name, $student->middle_name])));
        if ($fullName === '') {
            $missing['full_name'] = 'фамилия и имя';
        }
        if ($student->birth_date === null) {
            $missing['birth_date'] = 'дата рождения';
        }
        if ((int) $student->course < 1) {
            $missing['course'] = 'курс';
        }
        if (blank($student->enrollment_order_number)) {
            $missing['enrollment_order_number'] = 'номер приказа о зачислении';
        }
        if ($student->enrollment_order_date === null) {
            $missing['enrollment_order_date'] = 'дата приказа о зачислении';
        }
        if ($group === null) {
            $missing['group_id'] = 'группа';
        }
        if ($program === null) {
            $missing['education_program_id'] = 'образовательная программа группы';
        } else {
            if (blank($program->study_form)) {
                $missing['study_form'] = 'форма обучения в образовательной программе';
            }
            if ((float) $program->study_years <= 0) {
                $missing['study_years'] = 'срок обучения в образовательной программе';
            }
        }
        if ($group !== null && (int) $group->year_start < 1) {
            $missing['year_start'] = 'год набора группы';
        }

        $specialty = $program?->specialty?->name ?: $group?->specialty;
        if (blank($specialty)) {
            $missing['specialty'] = 'специальность';
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'student_id' => 'Справку выписать не из чего: '.implode(', ', $missing)
                    .'. Пустое место в подписанном документе хуже отказа.',
            ] + array_map(fn (string $label): string => 'Не заполнено: '.$label, $missing));
        }

        $yearStart = (int) $group->year_start;
        $studyStart = Carbon::create($yearStart, 9, 1)->toDateString();
        // Срок обучения записан как 3.8 — три года десять месяцев. Выпуск
        // приходится на 30 июня следующего календарного года, поэтому дробная
        // часть округляется вверх: 2026 + 4 = 2030, и это сходится с образцом.
        $studyEnd = Carbon::create($yearStart + (int) ceil((float) $program->study_years), 6, 30)->toDateString();

        $course = (int) $student->course;

        return [
            'full_name' => $fullName,
            'birth_date' => $student->birth_date->toDateString(),
            'course' => $course,
            'specialty' => $specialty,
            'study_form' => $program->study_form,
            'enrollment_order_number' => $student->enrollment_order_number,
            'enrollment_order_date' => $student->enrollment_order_date->toDateString(),
            // Приказ о переводе — общий на весь колледж и меняется раз в год,
            // поэтому берётся из настройки, а не из карточки студента. У
            // первого курса его нет: переводить неоткуда.
            'transfer_order_number' => $course > 1 ? (SettingService::value(self::GROUP, 'transfer_order_number', '') ?: null) : null,
            'transfer_order_date' => $course > 1 ? (SettingService::value(self::GROUP, 'transfer_order_date', '') ?: null) : null,
            'study_start' => $studyStart,
            'study_end' => $studyEnd,
        ];
    }
}
