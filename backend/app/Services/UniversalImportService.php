<?php

namespace App\Services;

use App\Models\ApplicantApplication;
use App\Models\Classroom;
use App\Models\EducationProgram;
use App\Models\Group;
use App\Models\ImportJob;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class UniversalImportService
{
    public const MODE_CREATE = 'create';
    public const MODE_UPDATE = 'update';
    public const MODE_SKIP_DUPLICATES = 'skip_duplicates';

    public function __construct(private readonly AutoCodeService $autoCodeService)
    {
    }

    public function config(): array
    {
        return [
            'types' => $this->targetsForFrontend(),
            'modes' => [
                ['value' => self::MODE_CREATE, 'label' => 'Создать новые записи', 'description' => 'Создает строки, дубли по ключу попадут в ошибки.'],
                ['value' => self::MODE_UPDATE, 'label' => 'Обновить существующие по ключу', 'description' => 'Обновляет найденные записи, новые строки пропускает.'],
                ['value' => self::MODE_SKIP_DUPLICATES, 'label' => 'Пропустить дубли', 'description' => 'Создает только новые записи, существующие по ключу пропускает.'],
            ],
            'formats' => ['csv', 'xlsx'],
        ];
    }

    public function createPreview(UploadedFile $file, string $dataType, ?User $user): ImportJob
    {
        $this->assertKnownType($dataType);
        $this->assertSupportedFile($file);

        $storedPath = $file->store('imports', 'local');
        $parsed = $this->parseStoredFile($storedPath, $file->getClientOriginalName());
        $mapping = $this->suggestMapping($dataType, $parsed['headers']);
        $validation = $this->validateRows($dataType, $mapping, $parsed['rows'], self::MODE_CREATE, 20);

        return ImportJob::create([
            'user_id' => $user?->id,
            'data_type' => $dataType,
            'mode' => self::MODE_CREATE,
            'status' => 'preview',
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'headers' => $parsed['headers'],
            'mapping' => $mapping,
            'preview_rows' => array_slice($parsed['rows'], 0, 20),
            'validation_errors' => $validation['errors'],
            'total_rows' => count($parsed['rows']),
            'error_count' => $validation['error_count'],
        ]);
    }

    public function validateJob(ImportJob $job, array $mapping, string $mode): ImportJob
    {
        $this->assertKnownType($job->data_type);
        $this->assertKnownMode($mode);

        $parsed = $this->parseStoredFile($job->stored_path, $job->original_filename);
        $validation = $this->validateRows($job->data_type, $mapping, $parsed['rows'], $mode, 100);
        $job->update([
            'mode' => $mode,
            'mapping' => $mapping,
            'status' => $validation['error_count'] > 0 ? 'validation_failed' : 'validated',
            'validation_errors' => $validation['errors'],
            'total_rows' => count($parsed['rows']),
            'error_count' => $validation['error_count'],
        ]);

        return $job->refresh();
    }

    public function confirmJob(ImportJob $job, array $mapping, string $mode): ImportJob
    {
        $this->assertKnownType($job->data_type);
        $this->assertKnownMode($mode);

        $parsed = $this->parseStoredFile($job->stored_path, $job->original_filename);
        $result = $this->importRows($job->data_type, $mapping, $parsed['rows'], $mode);
        $job->update([
            'mode' => $mode,
            'mapping' => $mapping,
            'status' => $result['error_count'] > 0 ? 'completed_with_errors' : 'completed',
            'validation_errors' => $result['errors'],
            'result' => $result,
            'total_rows' => $result['total_rows'],
            'created_count' => $result['created'],
            'updated_count' => $result['updated'],
            'skipped_count' => $result['skipped'],
            'error_count' => $result['error_count'],
        ]);

        return $job->refresh();
    }

    public function targets(): array
    {
        return [
            'students' => [
                'label' => 'Студенты', 'model' => Student::class, 'key' => ['email'],
                'fields' => [
                    'last_name' => ['label' => 'Фамилия', 'required' => true, 'aliases' => ['фамилия', 'last_name']],
                    'first_name' => ['label' => 'Имя', 'required' => true, 'aliases' => ['имя', 'first_name']],
                    'middle_name' => ['label' => 'Отчество', 'required' => false, 'aliases' => ['отчество', 'middle_name']],
                    'group_id' => ['label' => 'ID группы', 'required' => false, 'aliases' => ['group_id', 'id группы']],
                    'group_name' => ['label' => 'Группа', 'required' => false, 'aliases' => ['группа', 'group', 'group_name']],
                    'birth_date' => ['label' => 'Дата рождения', 'required' => false, 'aliases' => ['дата рождения', 'birth_date']],
                    'phone' => ['label' => 'Телефон', 'required' => false, 'aliases' => ['телефон', 'phone']],
                    'email' => ['label' => 'Email', 'required' => false, 'aliases' => ['email', 'почта', 'e-mail']],
                    'status' => ['label' => 'Статус', 'required' => false, 'aliases' => ['статус', 'status']],
                    'enrollment_date' => ['label' => 'Дата зачисления', 'required' => false, 'aliases' => ['дата зачисления', 'enrollment_date']],
                ],
            ],
            'groups' => [
                'label' => 'Группы', 'model' => Group::class, 'key' => ['name'],
                'fields' => [
                    'name' => ['label' => 'Название', 'required' => true, 'aliases' => ['группа', 'название', 'name']],
                    'specialty' => ['label' => 'Специальность', 'required' => true, 'aliases' => ['специальность', 'specialty']],
                    'course' => ['label' => 'Курс', 'required' => true, 'aliases' => ['курс', 'course']],
                    'year_start' => ['label' => 'Год набора', 'required' => true, 'aliases' => ['год набора', 'year_start']],
                    'education_program_id' => ['label' => 'ID программы', 'required' => false, 'aliases' => ['education_program_id']],
                    'education_program_name' => ['label' => 'Программа', 'required' => false, 'aliases' => ['программа', 'образовательная программа']],
                ],
            ],
            'teachers' => [
                'label' => 'Преподаватели', 'model' => Teacher::class, 'key' => ['email'],
                'fields' => [
                    'last_name' => ['label' => 'Фамилия', 'required' => true, 'aliases' => ['фамилия', 'last_name']],
                    'first_name' => ['label' => 'Имя', 'required' => true, 'aliases' => ['имя', 'first_name']],
                    'middle_name' => ['label' => 'Отчество', 'required' => false, 'aliases' => ['отчество', 'middle_name']],
                    'phone' => ['label' => 'Телефон', 'required' => false, 'aliases' => ['телефон', 'phone']],
                    'email' => ['label' => 'Email', 'required' => false, 'aliases' => ['email', 'почта', 'e-mail']],
                    'position' => ['label' => 'Должность', 'required' => false, 'aliases' => ['должность', 'position']],
                    'department' => ['label' => 'Отделение', 'required' => false, 'aliases' => ['отделение', 'кафедра', 'department']],
                    'is_active' => ['label' => 'Активен', 'required' => false, 'aliases' => ['активен', 'is_active']],
                ],
            ],
            'subjects' => [
                'label' => 'Дисциплины', 'model' => Subject::class, 'key' => ['code'],
                'fields' => [
                    'name' => ['label' => 'Название', 'required' => true, 'aliases' => ['дисциплина', 'название', 'name']],
                    'code' => ['label' => 'Код', 'required' => false, 'aliases' => ['код', 'code']],
                    'department' => ['label' => 'Отделение', 'required' => false, 'aliases' => ['отделение', 'кафедра', 'department']],
                    'description' => ['label' => 'Описание', 'required' => false, 'aliases' => ['описание', 'description']],
                ],
            ],
            'classrooms' => [
                'label' => 'Аудитории', 'model' => Classroom::class, 'key' => ['number', 'building'],
                'fields' => [
                    'number' => ['label' => 'Номер', 'required' => true, 'aliases' => ['номер', 'аудитория', 'number']],
                    'building' => ['label' => 'Корпус', 'required' => false, 'aliases' => ['корпус', 'building']],
                    'floor' => ['label' => 'Этаж', 'required' => false, 'aliases' => ['этаж', 'floor']],
                    'capacity' => ['label' => 'Вместимость', 'required' => false, 'aliases' => ['вместимость', 'capacity']],
                    'type' => ['label' => 'Тип', 'required' => false, 'aliases' => ['тип', 'type']],
                    'description' => ['label' => 'Описание', 'required' => false, 'aliases' => ['описание', 'description']],
                ],
            ],
            'applicants' => [
                'label' => 'Абитуриенты', 'model' => ApplicantApplication::class, 'key' => ['email'],
                'fields' => [
                    'last_name' => ['label' => 'Фамилия', 'required' => true, 'aliases' => ['фамилия', 'last_name']],
                    'first_name' => ['label' => 'Имя', 'required' => true, 'aliases' => ['имя', 'first_name']],
                    'middle_name' => ['label' => 'Отчество', 'required' => false, 'aliases' => ['отчество', 'middle_name']],
                    'education_program_id' => ['label' => 'ID программы', 'required' => false, 'aliases' => ['education_program_id']],
                    'education_program_name' => ['label' => 'Программа', 'required' => false, 'aliases' => ['программа', 'образовательная программа']],
                    'birth_date' => ['label' => 'Дата рождения', 'required' => false, 'aliases' => ['дата рождения', 'birth_date']],
                    'phone' => ['label' => 'Телефон', 'required' => false, 'aliases' => ['телефон', 'phone']],
                    'email' => ['label' => 'Email', 'required' => false, 'aliases' => ['email', 'почта', 'e-mail']],
                    'education_base' => ['label' => 'База', 'required' => false, 'aliases' => ['база', 'education_base']],
                    'status' => ['label' => 'Статус', 'required' => false, 'aliases' => ['статус', 'status']],
                    'submitted_at' => ['label' => 'Дата подачи', 'required' => false, 'aliases' => ['дата подачи', 'submitted_at']],
                    'comment' => ['label' => 'Комментарий', 'required' => false, 'aliases' => ['комментарий', 'comment']],
                ],
            ],
        ];
    }

    private function targetsForFrontend(): array
    {
        return collect($this->targets())->map(fn ($target, $key) => [
            'value' => $key,
            'label' => $target['label'],
            'key_fields' => $target['key'],
            'fields' => collect($target['fields'])->map(fn ($field, $fieldKey) => [
                'value' => $fieldKey,
                'label' => $field['label'],
                'required' => $field['required'],
            ])->values()->all(),
        ])->values()->all();
    }

    private function assertKnownType(string $dataType): void
    {
        if (!isset($this->targets()[$dataType])) {
            throw new RuntimeException('Неизвестный тип данных для импорта.');
        }
    }

    private function assertKnownMode(string $mode): void
    {
        if (!in_array($mode, [self::MODE_CREATE, self::MODE_UPDATE, self::MODE_SKIP_DUPLICATES], true)) {
            throw new RuntimeException('Неизвестный режим импорта.');
        }
    }

    private function assertSupportedFile(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['csv', 'txt', 'xlsx'], true)) {
            throw new RuntimeException('Поддерживаются только CSV и XLSX файлы.');
        }
    }

    private function parseStoredFile(string $storedPath, ?string $filename): array
    {
        $path = Storage::disk('local')->path($storedPath);
        $extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
        return $extension === 'xlsx' ? $this->parseXlsx($path) : $this->parseCsv($path);
    }

    private function parseCsv(string $path): array
    {
        $sample = file_get_contents($path, false, null, 0, 4096) ?: '';
        $delimiter = $this->detectDelimiter($sample);
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new RuntimeException('Не удалось открыть файл импорта.');
        }

        $headers = fgetcsv($handle, 0, $delimiter) ?: [];
        $headers = $this->normalizeHeaders($headers);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $assoc = [];
            foreach ($headers as $index => $header) {
                $assoc[$header] = trim((string) ($row[$index] ?? ''));
            }
            if (count(array_filter($assoc, fn ($value) => $value !== '')) > 0) {
                $rows[] = $assoc;
            }
        }
        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function parseXlsx(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('На сервере недоступно чтение XLSX. Используйте CSV или включите ZipArchive.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Не удалось открыть XLSX файл.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false) {
            throw new RuntimeException('В XLSX не найден первый лист.');
        }

        $sheet = new SimpleXMLElement($sheetXml);
        $rawRows = [];
        foreach ($sheet->sheetData->row as $rowNode) {
            $row = [];
            foreach ($rowNode->c as $cell) {
                $ref = (string) $cell['r'];
                $index = $this->columnIndexFromCellRef($ref);
                $row[$index] = $this->xlsxCellValue($cell, $sharedStrings);
            }
            if ($row !== []) {
                ksort($row);
                $rawRows[] = $row;
            }
        }
        if ($rawRows === []) {
            return ['headers' => [], 'rows' => []];
        }

        $max = max(array_map(fn ($row) => max(array_keys($row)), $rawRows));
        $headers = [];
        for ($i = 0; $i <= $max; $i++) {
            $headers[] = trim((string) ($rawRows[0][$i] ?? ''));
        }
        $headers = $this->normalizeHeaders($headers);
        $rows = [];
        foreach (array_slice($rawRows, 1) as $rawRow) {
            $assoc = [];
            foreach ($headers as $index => $header) {
                $assoc[$header] = trim((string) ($rawRow[$index] ?? ''));
            }
            if (count(array_filter($assoc, fn ($value) => $value !== '')) > 0) {
                $rows[] = $assoc;
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $strings = [];
        $shared = new SimpleXMLElement($xml);
        foreach ($shared->si as $item) {
            $text = '';
            if (isset($item->t)) {
                $text = (string) $item->t;
            } elseif (isset($item->r)) {
                foreach ($item->r as $run) {
                    $text .= (string) $run->t;
                }
            }
            $strings[] = $text;
        }
        return $strings;
    }

    private function xlsxCellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];
        if ($type === 's') {
            return (string) ($sharedStrings[(int) $cell->v] ?? '');
        }
        if ($type === 'inlineStr') {
            return (string) ($cell->is->t ?? '');
        }
        return (string) ($cell->v ?? '');
    }

    private function columnIndexFromCellRef(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }
        return max(0, $index - 1);
    }

    private function detectDelimiter(string $sample): string
    {
        $delimiters = [';' => substr_count($sample, ';'), ',' => substr_count($sample, ','), "\t" => substr_count($sample, "\t")];
        arsort($delimiters);
        return array_key_first($delimiters) ?: ';';
    }

    private function normalizeHeaders(array $headers): array
    {
        return array_values(array_map(fn ($header) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $header)), $headers));
    }

    private function suggestMapping(string $dataType, array $headers): array
    {
        $target = $this->targets()[$dataType];
        $mapping = [];
        foreach ($target['fields'] as $fieldKey => $field) {
            $mapping[$fieldKey] = null;
            foreach ($headers as $header) {
                if (in_array($this->normalizeKey($header), array_map(fn ($alias) => $this->normalizeKey($alias), $field['aliases']), true)) {
                    $mapping[$fieldKey] = $header;
                    break;
                }
            }
        }
        return $mapping;
    }

    private function normalizeKey(string $value): string
    {
        return mb_strtolower(trim(str_replace([' ', '-', '_'], '', $value)));
    }

    private function validateRows(string $dataType, array $mapping, array $rows, string $mode, int $limit): array
    {
        $errors = [];
        $errorCount = 0;
        foreach ($rows as $index => $row) {
            $prepared = $this->prepareData($dataType, $this->mappedRow($mapping, $row));
            $validator = Validator::make($prepared, $this->rules($dataType));
            if ($validator->fails()) {
                $errorCount++;
                if (count($errors) < $limit) {
                    $errors[] = ['row' => $index + 2, 'errors' => $validator->errors()->all(), 'data' => $prepared];
                }
            }
        }
        return ['errors' => $errors, 'error_count' => $errorCount];
    }

    private function importRows(string $dataType, array $mapping, array $rows, string $mode): array
    {
        $created = 0; $updated = 0; $skipped = 0; $errors = [];
        foreach ($rows as $index => $row) {
            $prepared = $this->prepareData($dataType, $this->mappedRow($mapping, $row));
            $validator = Validator::make($prepared, $this->rules($dataType));
            if ($validator->fails()) {
                $errors[] = ['row' => $index + 2, 'errors' => $validator->errors()->all(), 'data' => $prepared];
                continue;
            }
            try {
                $existing = $this->findExisting($dataType, $prepared);
                if ($mode === self::MODE_UPDATE) {
                    if (!$existing) { $skipped++; continue; }
                    $existing->update($this->payload($dataType, $prepared, true));
                    $updated++;
                    continue;
                }
                if ($existing) {
                    if ($mode === self::MODE_SKIP_DUPLICATES) { $skipped++; continue; }
                    $errors[] = ['row' => $index + 2, 'errors' => ['Дубликат по ключевому полю.'], 'data' => $prepared];
                    continue;
                }
                $modelClass = $this->targets()[$dataType]['model'];
                $modelClass::create($this->payload($dataType, $prepared));
                $created++;
            } catch (\Throwable $exception) {
                $errors[] = ['row' => $index + 2, 'errors' => [$exception->getMessage()], 'data' => $prepared];
            }
        }

        return [
            'total_rows' => count($rows),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, 200),
        ];
    }

    private function mappedRow(array $mapping, array $row): array
    {
        $data = [];
        foreach ($mapping as $field => $header) {
            $data[$field] = $header ? ($row[$header] ?? null) : null;
        }
        return $data;
    }

    private function prepareData(string $dataType, array $data): array
    {
        $data = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $data);
        foreach (['birth_date', 'enrollment_date', 'submitted_at'] as $field) {
            if (!empty($data[$field])) { $data[$field] = $this->normalizeDate((string) $data[$field]); }
        }
        if ($dataType === 'students') {
            $data['group_id'] = $this->resolveGroupId($data['group_id'] ?? null, $data['group_name'] ?? null);
            $data['status'] = $this->studentStatus($data['status'] ?? null);
        }
        if ($dataType === 'groups') {
            $data['education_program_id'] = $this->resolveProgramId($data['education_program_id'] ?? null, $data['education_program_name'] ?? null);
        }
        if ($dataType === 'teachers') {
            $data['is_active'] = $this->booleanValue($data['is_active'] ?? true);
        }
        if ($dataType === 'subjects') {
            $data['code'] = $data['code'] ?: $this->autoCodeService->subjectCode($data['name'] ?? null);
        }
        if ($dataType === 'applicants') {
            $data['education_program_id'] = $this->resolveProgramId($data['education_program_id'] ?? null, $data['education_program_name'] ?? null);
            $data['education_base'] = $this->educationBase($data['education_base'] ?? null);
            $data['status'] = $this->applicantStatus($data['status'] ?? null);
            $data['submitted_at'] = $data['submitted_at'] ?: now()->toDateString();
        }
        return $data;
    }

    private function payload(string $dataType, array $data, bool $update = false): array
    {
        $allowed = array_keys($this->targets()[$dataType]['fields']);
        $payload = Arr::only($data, $allowed);
        unset($payload['group_name'], $payload['education_program_name']);
        return array_filter($payload, fn ($value) => $update ? $value !== null : true);
    }

    private function rules(string $dataType): array
    {
        return match ($dataType) {
            'students' => ['group_id' => ['required', 'integer', 'exists:groups,id'], 'last_name' => ['required', 'string', 'max:255'], 'first_name' => ['required', 'string', 'max:255'], 'middle_name' => ['nullable', 'string', 'max:255'], 'birth_date' => ['nullable', 'date'], 'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'status' => ['required', 'in:active,academic_leave,graduated,expelled'], 'enrollment_date' => ['nullable', 'date']],
            'groups' => ['name' => ['required', 'string', 'max:255'], 'specialty' => ['required', 'string', 'max:255'], 'education_program_id' => ['nullable', 'integer', 'exists:education_programs,id'], 'course' => ['required', 'integer', 'min:1', 'max:6'], 'year_start' => ['required', 'integer', 'min:2000', 'max:2100']],
            'teachers' => ['last_name' => ['required', 'string', 'max:255'], 'first_name' => ['required', 'string', 'max:255'], 'middle_name' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'position' => ['nullable', 'string', 'max:255'], 'department' => ['nullable', 'string', 'max:255'], 'is_active' => ['boolean']],
            'subjects' => ['name' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:100'], 'department' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string']],
            'classrooms' => ['number' => ['required', 'string', 'max:50'], 'building' => ['nullable', 'string', 'max:255'], 'floor' => ['nullable', 'integer', 'min:0', 'max:50'], 'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'], 'type' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string']],
            'applicants' => ['education_program_id' => ['required', 'integer', 'exists:education_programs,id'], 'last_name' => ['required', 'string', 'max:255'], 'first_name' => ['required', 'string', 'max:255'], 'middle_name' => ['nullable', 'string', 'max:255'], 'birth_date' => ['nullable', 'date'], 'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'education_base' => ['required', 'in:after_9,after_11'], 'status' => ['required', 'in:new,accepted,needs_clarification,rejected,enrolled'], 'submitted_at' => ['required', 'date'], 'comment' => ['nullable', 'string', 'max:5000']],
            default => [],
        };
    }

    private function findExisting(string $dataType, array $data)
    {
        $modelClass = $this->targets()[$dataType]['model'];
        if (in_array($dataType, ['students', 'teachers', 'applicants'], true) && !empty($data['email'])) {
            return $modelClass::where('email', $data['email'])->first();
        }
        return match ($dataType) {
            'groups' => Group::where('name', $data['name'] ?? null)->first(),
            'subjects' => !empty($data['code']) ? Subject::where('code', $data['code'])->first() : Subject::where('name', $data['name'] ?? null)->first(),
            'classrooms' => Classroom::where('number', $data['number'] ?? null)->where('building', $data['building'] ?? null)->first(),
            default => null,
        };
    }

    private function resolveGroupId($id, ?string $name): ?int
    {
        if ($id) { return (int) $id; }
        if ($name) { return Group::where('name', $name)->value('id'); }
        return null;
    }

    private function resolveProgramId($id, ?string $name): ?int
    {
        if ($id) { return (int) $id; }
        if ($name) { return EducationProgram::where('name', $name)->value('id'); }
        return null;
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value)) {
            [$day, $month, $year] = explode('.', $value);
            return "{$year}-{$month}-{$day}";
        }
        if (is_numeric($value) && (int) $value > 20000) {
            return gmdate('Y-m-d', ((int) $value - 25569) * 86400);
        }
        return $value;
    }

    private function studentStatus(?string $value): string
    {
        return match (mb_strtolower(trim((string) $value))) {
            'академический отпуск', 'academic_leave' => 'academic_leave',
            'выпускник', 'graduated' => 'graduated',
            'отчислен', 'expelled' => 'expelled',
            default => 'active',
        };
    }

    private function applicantStatus(?string $value): string
    {
        return match (mb_strtolower(trim((string) $value))) {
            'принято', 'accepted' => 'accepted',
            'уточнение', 'needs_clarification', 'неполный комплект' => 'needs_clarification',
            'отклонено', 'rejected' => 'rejected',
            'зачислен', 'enrolled' => 'enrolled',
            default => 'new',
        };
    }

    private function educationBase(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        return str_contains($value, '11') || $value === 'after_11' ? 'after_11' : 'after_9';
    }

    private function booleanValue($value): bool
    {
        if (is_bool($value)) { return $value; }
        return in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'да', 'активен', 'yes'], true);
    }
}
