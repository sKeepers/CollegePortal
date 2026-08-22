<?php

namespace App\Services;

use App\Models\Teacher;
use App\Services\Import\TeacherImportHandler;
use App\Support\Csv\CsvExport;
use App\Support\Phone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeacherCsvService
{
    /** Колонки, которые принимает собственный CSV-импорт преподавателей. */
    private const HEADERS = [
        'id',
        'last_name',
        'first_name',
        'middle_name',
        'birth_date',
        'snils',
        'phone',
        'email',
        'address',
        'position',
        'department',
        'is_active',
    ];

    /**
     * Заголовки шаблона импорта в технические имена этого сервиса. Нужно, чтобы
     * выгрузка грузилась не только «Универсальным импортом», но и сюда: файл
     * отдают как заготовку, и он обязан приниматься там, откуда его взяли.
     * Прежние английские заголовки продолжают работать — файлы по старому
     * образцу никуда не делись.
     */
    private const LABEL_TO_COLUMN = [
        'фамилия' => 'last_name',
        'имя' => 'first_name',
        'отчество' => 'middle_name',
        'дата рождения' => 'birth_date',
        'снилс' => 'snils',
        'телефон' => 'phone',
        'email' => 'email',
        'почта' => 'email',
        'адрес' => 'address',
        'должность' => 'position',
        'отделение' => 'department',
        'кафедра' => 'department',
        'активен' => 'is_active',
        'создать учетную запись' => 'auto_account',
    ];

    /**
     * Выгрузка идёт колонками шаблона импорта, а не машинными именами полей.
     * Владелец использует файл как заготовку: выгрузил, дополнил в Excel,
     * загрузил обратно — и он обязан приниматься там, откуда его взяли.
     *
     * Столбец «Создать учетную запись» выгружается пустым намеренно: обратная
     * загрузка не должна переоформлять учётные записи.
     */
    public function export(): StreamedResponse
    {
        $handler = app(TeacherImportHandler::class);

        return CsvExport::download('teachers.csv', $handler->templateHeaders(), function (callable $row): void {
            Teacher::query()
                ->with('person')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->chunk(200, function ($teachers) use ($row): void {
                    foreach ($teachers as $teacher) {
                        // Порядок обязан совпадать с templateHeaders() обработчика импорта.
                        $row([
                            $teacher->last_name,
                            $teacher->first_name,
                            $teacher->middle_name,
                            $teacher->person?->birth_date?->format('d.m.Y'),
                            $teacher->person?->snils,
                            Phone::forExport($teacher->phone),
                            $teacher->email,
                            $teacher->person?->address,
                            $teacher->position,
                            $teacher->department,
                            $teacher->is_active ? 'да' : 'нет',
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
        $csv->setCsvControl($this->detectDelimiter($file), '"', '');

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
            $payload = $this->normalizePayload($this->mapRow($headers, $row));
            $validator = Validator::make($payload, $this->rules(), $this->messages());

            if ($validator->fails()) {
                $errors[] = [
                    'line' => $line,
                    'messages' => $validator->errors()->all(),
                ];
                continue;
            }

            $validated = $validator->validated();
            $teacher = $this->findTeacher($validated);
            unset($validated['id']);

            // Раскладку полей делает обработчик универсального импорта: дата
            // рождения, СНИЛС и адрес принадлежат человеку, а не преподавателю,
            // и второй такой же раскладки здесь заводить не нужно.
            $handler = app(TeacherImportHandler::class);
            $prepared = $handler->prepare($validated);

            if ($teacher) {
                $teacher->update($handler->payload($prepared, true));
                $updated++;
            } else {
                $teacher = Teacher::create($handler->payload($prepared));
                $created++;
            }

            $handler->applyPerson($teacher, $prepared);
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

    /** Русская подпись шаблона или машинное имя — оба приводятся к одному ключу. */
    private function canonicalColumn(string $header): string
    {
        return self::LABEL_TO_COLUMN[mb_strtolower(trim($header))] ?? $header;
    }

    private function normalizePayload(array $payload): array
    {
        $payload = array_intersect_key($payload, array_flip(self::HEADERS));
        $payload = array_map(fn ($value) => $value === '' ? null : $value, $payload);
        $payload['is_active'] = $this->normalizeBoolean($payload['is_active'] ?? null);

        return $payload;
    }

    private function normalizeBoolean(?string $value): bool|string
    {
        if ($value === null) {
            return true;
        }

        $normalized = mb_strtolower($value);

        return match ($normalized) {
            '1', 'true', 'yes', 'y', 'да', 'активен', 'активна' => true,
            '0', 'false', 'no', 'n', 'нет', 'неактивен', 'неактивна' => false,
            default => $value,
        };
    }

    private function findTeacher(array $payload): ?Teacher
    {
        if (!empty($payload['id'])) {
            return Teacher::find($payload['id']);
        }

        if (!empty($payload['email'])) {
            $teacher = Teacher::where('email', $payload['email'])->first();

            if ($teacher) {
                return $teacher;
            }
        }

        return Teacher::query()
            ->where('last_name', $payload['last_name'])
            ->where('first_name', $payload['first_name'])
            ->where('middle_name', $payload['middle_name'] ?? null)
            ->first();
    }

    /** Правила берутся у обработчика импорта: два списка неизбежно разошлись бы. */
    private function rules(): array
    {
        return [
            'id' => ['nullable', 'integer', 'exists:teachers,id'],
            ...app(TeacherImportHandler::class)->rules(),
            'is_active' => ['required', 'boolean'],
        ];
    }

    private function messages(): array
    {
        return [
            'id.exists' => 'Преподаватель с указанным id не найден.',
            'last_name.required' => 'Не указана фамилия.',
            'first_name.required' => 'Не указано имя.',
            'email.email' => 'Email указан некорректно.',
            'is_active.boolean' => 'Активность должна быть 1/0, true/false или да/нет.',
        ];
    }
}
