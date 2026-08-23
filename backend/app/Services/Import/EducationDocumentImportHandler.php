<?php

namespace App\Services\Import;

use App\Models\Admissions\EducationDocument;
use App\Models\Student;
use App\Services\Admissions\EducationDocumentService;
use App\Services\StudentPersonService;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Загрузка документов об образовании — аттестатов — файлом.
 *
 * Аттестат остался последним из четырёх слоёв личного дела: карточка человека,
 * документ личности и СНИЛС собраны, а документа об образовании нет ни у кого
 * из 596. Из-за него **ноль карточек проходит проверку ФРДО** — и выпускник с
 * безупречным дипломом тоже не проходит, потому что ошибка приходит от карточки.
 *
 * Обработчик заведён заранее, до того как аттестаты соберут: когда владелец
 * ответит и принесёт серии с номерами, это должен быть день загрузки, а не день
 * разработки.
 *
 * **Студент ищется по номеру личного дела**, а не по ФИО: в учебной части
 * мыслят делом, и пара «буква + номер» уникальна. Если дела в строке нет, идёт
 * запасной путь — ФИО и дата рождения. Несопоставленная и неоднозначная строка
 * попадает в предпросмотр ошибкой, а не пропадает молча.
 *
 * **Учебное заведение — отдельная колонка.** 580 названий школ лежат в списках
 * учебной части, и `document_organization` — их законное место. Строка, где есть
 * только школа, но нет ни серии, ни номера, сегодня отклоняется: документ без
 * реквизитов ничего не удостоверяет, так решает `EducationDocumentService`.
 * Владелец может это правило смягчить — тогда те же файлы загрузятся без единой
 * правки здесь: условие живёт в службе, а не в обработчике.
 */
class EducationDocumentImportHandler extends AbstractImportHandler
{
    public function __construct(
        private readonly EducationDocumentService $documents,
        private readonly StudentPersonService $studentPeople,
    ) {
    }

    public function type(): string { return 'education_documents'; }

    public function label(): string { return 'Документы об образовании'; }

    public function modelClass(): string { return EducationDocument::class; }

    public function keyFields(): array { return ['personal_file_letter', 'personal_file_number']; }

    public function fields(): array
    {
        return [
            'personal_file_letter' => ['label' => 'Буква личного дела', 'required' => false, 'aliases' => ['буква личного дела', 'буква', 'personal_file_letter']],
            'personal_file_number' => ['label' => 'Номер личного дела', 'required' => false, 'aliases' => ['номер личного дела', 'личное дело', 'personal_file_number']],
            'last_name' => ['label' => 'Фамилия', 'required' => false, 'aliases' => ['фамилия', 'last_name']],
            'first_name' => ['label' => 'Имя', 'required' => false, 'aliases' => ['имя', 'first_name']],
            'middle_name' => ['label' => 'Отчество', 'required' => false, 'aliases' => ['отчество', 'middle_name']],
            'birth_date' => ['label' => 'Дата рождения', 'required' => false, 'aliases' => ['дата рождения', 'birth_date']],
            'document_type' => ['label' => 'Вид документа', 'required' => false, 'aliases' => ['вид документа', 'тип документа', 'document_type']],
            'series' => ['label' => 'Серия', 'required' => false, 'aliases' => ['серия', 'серия аттестата', 'series']],
            'number' => ['label' => 'Номер', 'required' => false, 'aliases' => ['номер', 'номер аттестата', 'number']],
            'issue_date' => ['label' => 'Дата выдачи', 'required' => false, 'aliases' => ['дата выдачи', 'issue_date']],
            'document_organization' => ['label' => 'Учебное заведение', 'required' => false, 'aliases' => ['учебное заведение', 'школа', 'кем выдан', 'document_organization']],
            'graduation_year' => ['label' => 'Год окончания', 'required' => false, 'aliases' => ['год окончания', 'graduation_year']],
            'average_score' => ['label' => 'Средний балл', 'required' => false, 'aliases' => ['средний балл', 'average_score']],
            'has_attachment' => ['label' => 'Есть приложение', 'required' => false, 'aliases' => ['есть приложение', 'приложение', 'has_attachment']],
        ];
    }

    public function templateHeaders(): array
    {
        return [
            'Буква личного дела', 'Номер личного дела', 'Фамилия', 'Имя', 'Отчество', 'Дата рождения',
            'Вид документа', 'Серия', 'Номер', 'Дата выдачи', 'Учебное заведение', 'Год окончания',
            'Средний балл', 'Есть приложение',
        ];
    }

    public function templateExample(): array
    {
        return [
            'К', '528', 'Иванова', 'Мария', 'Сергеевна', '14.03.2008',
            'Аттестат об основном общем образовании', '07АА', '0012345', '20.06.2023',
            'МБОУ СОШ № 44 города Ставрополя', '2023', '4,35', 'да',
        ];
    }

    public function rules(): array
    {
        return [
            'personal_file_letter' => ['nullable', 'string', 'max:1'],
            'personal_file_number' => ['nullable', 'string', 'max:50'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'document_type' => ['nullable', 'string', 'max:255'],
            'series' => ['nullable', 'string', 'max:20'],
            'number' => ['nullable', 'string', 'max:100'],
            'issue_date' => ['nullable', 'date'],
            'document_organization' => ['nullable', 'string', 'max:1000'],
            'graduation_year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'average_score' => ['nullable', 'numeric', 'min:2', 'max:5'],
            'has_attachment' => ['nullable'],
        ];
    }

    public function prepare(array $data): array
    {
        $data['birth_date'] = $this->normalizeDate($data['birth_date'] ?? null);
        $data['issue_date'] = $this->normalizeDate($data['issue_date'] ?? null);
        $data['personal_file_letter'] = mb_strtoupper(trim((string) ($data['personal_file_letter'] ?? '')));
        $data['average_score'] = $this->decimal($data['average_score'] ?? null);
        $data['has_attachment'] = ($data['has_attachment'] ?? '') === '' ? null : $this->booleanValue($data['has_attachment']);

        return $data;
    }

    /**
     * Ошибки, которые видно только после взгляда в базу: кого не нашли, кого
     * нашли дважды и у кого нет реквизитов. Все три обязаны попасть в
     * предпросмотр — на 593 строках такое не разглядеть после загрузки.
     */
    public function businessValidationErrors(array $data): array
    {
        // Служба ждёт карту «поле — сообщения», а не список строк: по полю
        // предпросмотр подсвечивает колонку в таблице.
        $errors = [];
        $students = $this->candidates($data);

        if ($students->isEmpty()) {
            $errors['personal_file_number'] = ['Студент не найден: проверьте номер личного дела либо ФИО и дату рождения.'];
        } elseif ($students->count() > 1) {
            $errors['personal_file_number'] = ['Подходит несколько студентов ('.$students->pluck('id')->join(', ').'): уточните строку.'];
        }

        $hasRequisites = filled($data['series'] ?? null) || filled($data['number'] ?? null);

        if (! $hasRequisites && filled($data['document_organization'] ?? null)) {
            $errors['series'] = ['Указано только учебное заведение, без серии и номера: документ об образовании так не создаётся.'];
        } elseif (! $hasRequisites) {
            $errors['series'] = ['Нет ни серии, ни номера документа.'];
        }

        return $errors;
    }

    public function findExisting(array $data): ?Model
    {
        $student = $this->candidates($data)->count() === 1 ? $this->candidates($data)->first() : null;

        if (! $student || $student->person_id === null) {
            return null;
        }

        return EducationDocument::query()
            ->where('person_id', $student->person_id)
            ->whereNull('archived_at')
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Пишем не моделью, а службой: она держит версии документа, вид из
     * справочника и признак основного. Прямая запись всё это обошла бы.
     */
    public function import(array $data, string $mode): string
    {
        $students = $this->candidates($data);

        if ($students->count() !== 1) {
            throw new RuntimeException($students->isEmpty()
                ? 'Студент не найден.'
                : 'Подходит несколько студентов.');
        }

        $student = $students->first();
        $existing = $this->findExisting($data);

        if ($mode === self::MODE_UPDATE && ! $existing) {
            return 'skipped';
        }

        if ($mode === self::MODE_SKIP_DUPLICATES && $existing) {
            return 'skipped';
        }

        $person = $this->studentPeople->ensureForStudent($student)['person'];

        $document = $this->documents->syncForPerson($person->id, [
            'document_type' => $data['document_type'] ?? null,
            'series' => $data['series'] ?? null,
            'number' => $data['number'] ?? null,
            'issue_date' => $data['issue_date'] ?? null,
            'document_organization' => $data['document_organization'] ?? null,
            'graduation_year' => $data['graduation_year'] ?? null,
            'average_score' => $data['average_score'] ?? null,
            'has_attachment' => $data['has_attachment'] ?? null,
        ]);

        if (! $document) {
            throw new RuntimeException('Документ не создан: нужны серия или номер.');
        }

        return $existing ? 'updated' : 'created';
    }

    /** @return \Illuminate\Support\Collection<int, Student> */
    private function candidates(array $data)
    {
        $letter = trim((string) ($data['personal_file_letter'] ?? ''));
        $number = trim((string) ($data['personal_file_number'] ?? ''));

        // Пара «буква + номер» уникальна: у каждой буквы своя нумерация, и
        // номер сам по себе ничего не значит.
        if ($letter !== '' && $number !== '') {
            return Student::query()
                ->whereNull('archived_at')
                ->where('personal_file_letter', $letter)
                ->where('personal_file_number', $number)
                ->get();
        }

        $last = trim((string) ($data['last_name'] ?? ''));
        $first = trim((string) ($data['first_name'] ?? ''));
        $birth = $data['birth_date'] ?? null;

        if ($last === '' || $first === '' || ! $birth) {
            return Student::query()->whereRaw('1 = 0')->get();
        }

        return Student::query()
            ->whereNull('archived_at')
            ->where('last_name', $last)
            ->where('first_name', $first)
            ->whereDate('birth_date', $birth)
            ->get();
    }

    private function decimal(mixed $value): ?float
    {
        $value = str_replace(',', '.', trim((string) $value));

        return $value === '' ? null : (float) $value;
    }
}
