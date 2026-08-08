<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Student;
use App\Services\Admissions\PersonDocumentService;
use App\Services\Import\StudentImportHandler;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentCsvService
{
    public function __construct(private readonly PersonDocumentService $personDocuments)
    {
    }

    /** Колонки, которые принимает собственный CSV-импорт студентов. */
    private const HEADERS = [
        'id', 'group_id', 'group', 'last_name', 'first_name', 'middle_name', 'birth_date',
        'phone', 'email', 'snils', 'address', 'passport_series', 'passport_number',
        'passport_issue_date', 'passport_issued_by', 'status', 'course', 'education_form',
        'enrollment_date', 'enrollment_order_number', 'enrollment_order_date',
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
        'серия паспорта' => 'passport_series',
        'номер паспорта' => 'passport_number',
        'дата выдачи паспорта' => 'passport_issue_date',
        'кем выдан паспорт' => 'passport_issued_by',
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

        return response()->streamDownload(function () use ($handler): void {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $handler->templateHeaders(), ';');

            Student::query()
                ->with('group')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->chunk(200, function ($students) use ($output): void {
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
                        fputcsv($output, [
                            $student->last_name,
                            $student->first_name,
                            $student->middle_name,
                            $student->group?->name,
                            $student->birth_date?->toDateString(),
                            $student->phone,
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
                        ], ';');
                    }
                });

            fclose($output);
        }, 'students.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
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

            unset($validated['id'], $validated['group']);

            if ($student) {
                $student->update($validated);
                $updated++;
            } else {
                Student::create($validated);
                $created++;
            }
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
            'enrollment_order_date' => ['nullable', 'date'],
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
