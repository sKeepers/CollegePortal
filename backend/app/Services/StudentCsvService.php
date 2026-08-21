<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Student;
use App\Rules\FreePersonalFileNumber;
use App\Services\Admissions\EducationDocumentService;
use App\Services\Admissions\IdentityDocumentService;
use App\Services\Admissions\PersonDocumentService;
use App\Services\Import\StudentImportHandler;
use DateTimeImmutable;
use App\Support\Csv\CsvExport;
use App\Support\Phone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentCsvService
{
    public function __construct(
        private readonly PersonDocumentService $personDocuments,
        private readonly StudentPersonService $studentPeople,
        private readonly IdentityDocumentService $identityDocuments,
        private readonly EducationDocumentService $educationDocuments,
    ) {
    }

    /** Колонки, которые принимает собственный CSV-импорт студентов. */
    private const HEADERS = [
        'id', 'group_id', 'group', 'last_name', 'first_name', 'middle_name', 'birth_date',
        'phone', 'email', 'snils', 'address', 'passport_series', 'passport_number',
        'passport_issue_date', 'passport_issued_by', 'status', 'course', 'education_form',
        'enrollment_date', 'enrollment_order_number', 'enrollment_order_date', 'personal_file_number',
        'education_document_type', 'education_document_series', 'education_document_number',
        'education_document_issue_date', 'education_document_organization', 'education_graduation_year',
    ];

    /** Колонки документа об образовании: у студента таких полей нет, они уходят человеку. */
    private const EDUCATION_DOCUMENT_COLUMNS = [
        'education_document_type', 'education_document_series', 'education_document_number',
        'education_document_issue_date', 'education_document_organization', 'education_graduation_year',
    ];

    /**
     * Заголовки шаблона импорта в технические имена этого сервиса. Нужно, чтобы
     * выгрузка грузилась не только «Универсальным импортом», но и сюда: файл
     * отдают как заготовку, и он обязан приниматься там, откуда его взяли.
     */
    private const LABEL_TO_COLUMN = [
        'фамилия' => 'last_name',
        'имя' => 'first_name',
        'отчество' => 'middle_name',
        'группа' => 'group',
        'дата рождения' => 'birth_date',
        'телефон' => 'phone',
        'email' => 'email',
        'снилс' => 'snils',
        'статус' => 'status',
        'дата зачисления' => 'enrollment_date',
        'курс' => 'course',
        'форма обучения' => 'education_form',
        'адрес' => 'address',
        'приказ о зачислении' => 'enrollment_order_number',
        'дата приказа о зачислении' => 'enrollment_order_date',
        // В бумажных списках колледжа этот номер стоит в столбце «Алфавитный
        // классификатор» — принимаем оба написания, чтобы файл заходил как есть.
        'номер личного дела' => 'personal_file_number',
        'личное дело' => 'personal_file_number',
        'алфавитный классификатор' => 'personal_file_number',
        'серия паспорта' => 'passport_series',
        'номер паспорта' => 'passport_number',
        'дата выдачи паспорта' => 'passport_issue_date',
        'кем выдан паспорт' => 'passport_issued_by',
        'тип документа об образовании' => 'education_document_type',
        'серия документа об образовании' => 'education_document_series',
        'номер документа об образовании' => 'education_document_number',
        'дата выдачи документа об образовании' => 'education_document_issue_date',
        'учебное заведение' => 'education_document_organization',
        'год окончания' => 'education_graduation_year',
    ];

    /**
     * Выгрузка отдаёт колонки шаблона импорта, взятые у самого обработчика.
     * Раньше здесь был свой список технических имён: специальности в нём не было
     * вовсе (она хранится у группы, а не у студента), зато были поля паспорта,
     * которых импорт студентов не понимает и молча отбрасывал. Файл нужен как
     * заготовка для заполнения и обратной загрузки, поэтому источник колонок
     * должен быть один.
     */
    public function export(): StreamedResponse
    {
        $handler = app(StudentImportHandler::class);

        return CsvExport::download('students.csv', $handler->templateHeaders(), function (callable $row) use ($handler): void {
            Student::query()
                ->with('group')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->chunk(200, function ($students) use ($row): void {
                    // Документы принадлежат человеку, поэтому выгружаются пачкой на страницу,
                    // а не запросом на строку.
                    $personIds = $students->pluck('person_id')->filter()->map(fn ($id): int => (int) $id)->all();
                    $identities = $this->personDocuments->currentIdentityForPeople($personIds);
                    $educations = $this->personDocuments->currentEducationForPeople($personIds);

                    foreach ($students as $student) {
                        $personId = $student->person_id !== null ? (int) $student->person_id : null;
                        $identity = $personId !== null ? $identities->get($personId) : null;
                        $education = $personId !== null ? $educations->get($personId) : null;

                        // Порядок обязан совпадать с templateHeaders() обработчика импорта.
                        // «Создать учетную запись» выгружается пустым: обратная
                        // загрузка не должна переоформлять учётные записи.
                        $row([
                            $student->last_name,
                            $student->first_name,
                            $student->middle_name,
                            $student->group?->name,
                            $student->birth_date?->toDateString(),
                            Phone::forExport($student->phone),
                            $student->email,
                            $student->snils,
                            $student->status,
                            $student->enrollment_date?->toDateString(),
                            $student->course,
                            $student->group?->specialty,
                            $student->education_form,
                            $student->address,
                            $student->enrollment_order_number,
                            $student->enrollment_order_date?->toDateString(),
                            $student->personal_file_number,
                            $identity?->series ?: $student->passport_series,
                            $identity?->number ?: $student->passport_number,
                            $identity?->issue_date?->toDateString() ?: $student->passport_issue_date?->toDateString(),
                            $identity?->issued_by ?: $student->passport_issued_by,
                            $education?->documentType?->name,
                            $education?->series,
                            $education?->number,
                            $education?->issue_date?->toDateString(),
                            $education?->document_organization,
                            $education?->graduation_year,
                            '',
                        ]);
                    }
                });
        });
    }

    public function import(UploadedFile $file): array
    {
        $csv = new SplFileObject($file->getRealPath());
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $csv->setCsvControl($this->detectDelimiter($file));

        $headers = null;
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($csv as $index => $row) {
            if ($row === [null] || $row === false) {
                continue;
            }

            $row = array_map(fn ($value) => trim((string) $value), $row);

            if ($headers === null) {
                $headers = $this->normalizeHeaders($row);
                continue;
            }

            $line = $index + 1;
            $payload = $this->mapRow($headers, $row);
            $payload = $this->normalizePayload($payload);

            $validator = Validator::make($payload, $this->rules(), $this->messages());

            if ($validator->fails()) {
                $errors[] = [
                    'line' => $line,
                    'messages' => $validator->errors()->all(),
                ];
                continue;
            }

            $validated = $validator->validated();
            $student = isset($validated['id']) ? Student::find($validated['id']) : $this->findExisting($validated);

            // Номер личного дела обязан быть свободен в пределах своей буквы:
            // у каждой буквы алфавита своя нумерация. Проверять это правилом
            // формы нельзя — нужна фамилия из той же строки, а `rules()` строки
            // не видит; и нужен уже найденный студент, чтобы своя же запись не
            // мешала обновлению.
            $conflicts = [];
            if (filled($validated['personal_file_number'] ?? null)) {
                (new FreePersonalFileNumber($validated['last_name'] ?? null, $student?->id))
                    ->validate('personal_file_number', $validated['personal_file_number'], function (string $message) use (&$conflicts): void {
                        $conflicts[] = $message;
                    });
            }

            if ($conflicts !== []) {
                $errors[] = ['line' => $line, 'messages' => $conflicts];
                continue;
            }

            // Документ об образовании — не поле студента, а документ человека.
            $educationDocument = Arr::only($validated, self::EDUCATION_DOCUMENT_COLUMNS);
            $validated = Arr::except($validated, [...self::EDUCATION_DOCUMENT_COLUMNS, 'id', 'group']);

            if ($student) {
                $student->update($validated);
                $updated++;
            } else {
                $student = Student::create($validated);
                $created++;
            }

            $this->syncDocuments($student, $validated, $educationDocument);
        }

        if ($headers === null) {
            throw new RuntimeException('CSV-файл пустой.');
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    /**
     * Паспорт и документ об образовании принадлежат человеку, поэтому загрузка через
     * реестр обязана делать то же, что «Универсальный импорт»: завести человека и
     * положить документы ему. Иначе один и тот же файл давал бы разный результат
     * в зависимости от того, какой кнопкой его загрузили.
     *
     * @param array<string, mixed> $student данные строки, уже отфильтрованные под модель
     * @param array<string, mixed> $educationDocument колонки документа об образовании
     */
    private function syncDocuments(Student $model, array $student, array $educationDocument): void
    {
        $person = $this->studentPeople->ensureForStudent($model)['person'];

        $this->identityDocuments->syncPassportForPerson($person->id, [
            'series' => $student['passport_series'] ?? null,
            'number' => $student['passport_number'] ?? null,
            'issue_date' => $student['passport_issue_date'] ?? null,
            'issued_by' => $student['passport_issued_by'] ?? null,
        ]);

        $this->educationDocuments->syncForPerson($person->id, [
            'document_type' => $educationDocument['education_document_type'] ?? null,
            'series' => $educationDocument['education_document_series'] ?? null,
            'number' => $educationDocument['education_document_number'] ?? null,
            'issue_date' => $educationDocument['education_document_issue_date'] ?? null,
            'document_organization' => $educationDocument['education_document_organization'] ?? null,
            'graduation_year' => $educationDocument['education_graduation_year'] ?? null,
        ]);
    }

    private function detectDelimiter(UploadedFile $file): string
    {
        $sample = file_get_contents($file->getRealPath(), false, null, 0, 4096) ?: '';

        return substr_count($sample, ';') >= substr_count($sample, ',') ? ';' : ',';
    }

    private function normalizeHeaders(array $headers): array
    {
        return array_map(function (string $header): string {
            return trim($header, "\xEF\xBB\xBF \t\n\r\0\x0B");
        }, $headers);
    }

    private function mapRow(array $headers, array $row): array
    {
        $payload = [];

        foreach ($headers as $index => $header) {
            if ($header !== '') {
                $payload[$this->canonicalColumn($header)] = $row[$index] ?? null;
            }
        }

        return $payload;
    }

    private function canonicalColumn(string $header): string
    {
        $key = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header));

        return self::LABEL_TO_COLUMN[$key] ?? $header;
    }

    private function normalizePayload(array $payload): array
    {
        $payload = array_intersect_key($payload, array_flip(self::HEADERS));
        $payload = array_map(fn ($value) => $value === '' ? null : $value, $payload);

        if (empty($payload['group_id']) && !empty($payload['group'])) {
            $payload['group_id'] = Group::where('name', $payload['group'])->value('id');
        }

        if (empty($payload['group_id']) && empty($payload['group']) && Group::count() === 1) {
            $payload['group_id'] = Group::query()->value('id');
        }

        $payload['birth_date'] = $this->normalizeDate($payload['birth_date'] ?? null);
        $payload['enrollment_date'] = $this->normalizeDate($payload['enrollment_date'] ?? null);
        $payload['enrollment_order_date'] = $this->normalizeDate($payload['enrollment_order_date'] ?? null);
        $payload['passport_issue_date'] = $this->normalizeDate($payload['passport_issue_date'] ?? null);
        $payload['education_document_issue_date'] = $this->normalizeDate($payload['education_document_issue_date'] ?? null);
        $payload['snils'] = isset($payload['snils']) ? preg_replace('/\D+/', '', (string) $payload['snils']) ?: null : null;

        if (empty($payload['status'])) {
            $payload['status'] = 'active';
        }

        return $payload;
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);

            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return $value;
    }

    private function rules(): array
    {
        return [
            'id' => ['nullable', 'integer', 'exists:students,id'],
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'group' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'snils' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:2000'],
            'passport_series' => ['nullable', 'string', 'max:20'],
            'passport_number' => ['nullable', 'string', 'max:100'],
            'passport_issue_date' => ['nullable', 'date'],
            'passport_issued_by' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(['active', 'academic_leave', 'graduated', 'expelled'])],
            'course' => ['nullable', 'integer', 'min:1', 'max:6'],
            'education_form' => ['nullable', 'string', 'max:80'],
            'enrollment_date' => ['nullable', 'date'],
            'enrollment_order_number' => ['nullable', 'string', 'max:100'],
            'personal_file_number' => ['nullable', 'string', 'max:50'],
            'enrollment_order_date' => ['nullable', 'date'],
            'education_document_type' => ['nullable', 'string', 'max:255'],
            'education_document_series' => ['nullable', 'string', 'max:20'],
            'education_document_number' => ['nullable', 'string', 'max:100'],
            'education_document_issue_date' => ['nullable', 'date'],
            'education_document_organization' => ['nullable', 'string', 'max:1000'],
            'education_graduation_year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
        ];
    }

    private function messages(): array
    {
        return [
            'group_id.required' => 'Не указана группа. Заполните group_id или group.',
            'group_id.exists' => 'Группа не найдена.',
            'last_name.required' => 'Не указана фамилия.',
            'first_name.required' => 'Не указано имя.',
            'birth_date.date' => 'Дата рождения должна быть в формате 2026-09-01 или 01.09.2026.',
            'email.email' => 'Email указан некорректно.',
            'status.in' => 'Статус должен быть active, academic_leave, graduated или expelled.',
            'enrollment_date.date' => 'Дата зачисления должна быть в формате 2026-09-01 или 01.09.2026.',
        ];
    }

    private function findExisting(array $data): ?Student
    {
        if (!empty($data['snils']) && ($student = Student::where('snils', $data['snils'])->first())) { return $student; }
        if (!empty($data['email']) && ($student = Student::where('email', $data['email'])->first())) { return $student; }
        if (!empty($data['birth_date'])) {
            return Student::where('last_name', $data['last_name'])
                ->where('first_name', $data['first_name'])
                ->where('birth_date', $data['birth_date'])
                ->first();
        }
        return null;
    }
}
