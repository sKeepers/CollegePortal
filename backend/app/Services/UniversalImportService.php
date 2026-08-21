<?php

namespace App\Services;

use App\Models\ImportJob;
use App\Models\User;
use App\Support\Csv\CsvExport;
use App\Services\Import\AdmissionImportHandler;
use App\Services\Import\ClassroomImportHandler;
use App\Services\Import\CurriculumImportHandler;
use App\Services\Import\EducationProgramImportHandler;
use App\Services\Import\EmployeeImportHandler;
use App\Services\Import\RfidCardIssueImportHandler;
use App\Services\Import\GroupImportHandler;
use App\Services\Import\ImportHandlerInterface;
use App\Services\Import\ScheduleImportHandler;
use App\Services\Import\SpecialtyImportHandler;
use App\Services\Import\StudentImportHandler;
use App\Services\Import\SubjectImportHandler;
use App\Services\Import\TeacherImportHandler;
use App\Services\Import\TeachingLoadImportHandler;
use App\Services\Admissions\EducationDocumentService;
use App\Services\Admissions\IdentityDocumentService;
use App\Services\Admissions\SnilsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;

class UniversalImportService
{
    public const MODE_CREATE = 'create';
    public const MODE_UPDATE = 'update';
    public const MODE_SKIP_DUPLICATES = 'skip_duplicates';

    /** @var array<string, ImportHandlerInterface> */
    private array $handlers = [];

    public function __construct(
        AutoCodeService $autoCodeService,
        ScheduleLessonService $scheduleLessonService,
        ScheduleEngineService $scheduleEngine,
        HrService $hrService,
        AccountProvisioningService $accounts,
        SnilsService $snils,
        PersonService $people,
        StudentPersonService $studentPeople,
        IdentityDocumentService $identityDocuments,
        EducationDocumentService $educationDocuments,
    ) {
        foreach ([
            new StudentImportHandler($snils, $studentPeople, $identityDocuments, $educationDocuments, $accounts),
            new GroupImportHandler(),
            new TeacherImportHandler($accounts, $people, $snils),
            new SubjectImportHandler($autoCodeService),
            new SpecialtyImportHandler(),
            new EducationProgramImportHandler(),
            new ClassroomImportHandler(),
            new AdmissionImportHandler(),
            new CurriculumImportHandler($autoCodeService),
            new TeachingLoadImportHandler(),
            new ScheduleImportHandler($scheduleLessonService, $scheduleEngine),
            new EmployeeImportHandler($hrService, $accounts),
            new RfidCardIssueImportHandler(),
        ] as $handler) {
            $this->handlers[$handler->type()] = $handler;
        }
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

    public function templateCsv(string $dataType): array
    {
        $handler = $this->handler($dataType);
        return ['filename' => "collegeportal_{$dataType}_template.csv", 'content' => $this->csvContent([$handler->templateHeaders(), $handler->templateExample()])];
    }

    public function templateXlsx(string $dataType): array
    {
        $handler = $this->handler($dataType);
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([$handler->templateHeaders(), $handler->templateExample()]);
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        foreach (range('A', $sheet->getHighestColumn()) as $column) { $sheet->getColumnDimension($column)->setAutoSize(true); }
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        ob_start();
        $writer->save('php://output');
        return ['filename' => "collegeportal_{$dataType}_template.xlsx", 'content' => ob_get_clean() ?: ''];
    }

    public function createPreview(UploadedFile $file, string $dataType, ?User $user): ImportJob
    {
        $this->assertKnownType($dataType);
        $this->assertSupportedFile($file);
        $storedPath = $file->store('imports', 'local');
        $parsed = $this->parseStoredFile($storedPath, $file->getClientOriginalName());
        $mapping = $this->suggestMapping($dataType, $parsed['headers']);
        $validation = $this->validateRows($dataType, $mapping, $parsed['rows'], 20);

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
        $validation = $this->validateRows($job->data_type, $mapping, $parsed['rows'], 100);
        $job->update(['mode' => $mode, 'mapping' => $mapping, 'status' => $validation['error_count'] > 0 ? 'validation_failed' : 'validated', 'validation_errors' => $validation['errors'], 'total_rows' => count($parsed['rows']), 'error_count' => $validation['error_count']]);
        return $job->refresh();
    }

    public function confirmJob(ImportJob $job, array $mapping, string $mode): ImportJob
    {
        $this->assertKnownType($job->data_type);
        $this->assertKnownMode($mode);
        $parsed = $this->parseStoredFile($job->stored_path, $job->original_filename);
        $result = $this->importRows($job->data_type, $mapping, $parsed['rows'], $mode);
        $job->update(['mode' => $mode, 'mapping' => $mapping, 'status' => $result['error_count'] > 0 ? 'completed_with_errors' : 'completed', 'validation_errors' => $result['errors'], 'result' => $result, 'total_rows' => $result['total_rows'], 'created_count' => $result['created'], 'updated_count' => $result['updated'], 'skipped_count' => $result['skipped'], 'error_count' => $result['error_count']]);
        return $job->refresh();
    }

    public function targets(): array
    {
        $targets = [];
        foreach ($this->handlers as $handler) {
            $targets[$handler->type()] = ['label' => $handler->label(), 'model' => $handler->modelClass(), 'key' => $handler->keyFields(), 'fields' => $handler->fields()];
        }
        return $targets;
    }

    private function targetsForFrontend(): array
    {
        return collect($this->handlers)->map(function (ImportHandlerInterface $handler) {
            $fields = $handler->fields();
            $labels = collect($fields)->mapWithKeys(fn ($field, $key) => [$key => $field['label']])->all();
            return [
                'value' => $handler->type(),
                'label' => $handler->label(),
                'key_fields' => $handler->keyFields(),
                'key_field_labels' => array_values(array_map(fn ($field) => $labels[$field] ?? $field, $handler->keyFields())),
                'required_fields' => collect($fields)->filter(fn ($field) => $field['required'])->map(fn ($field, $key) => ['value' => $key, 'label' => $field['label']])->values()->all(),
                'template' => ['format' => $handler->type() === 'employees' ? 'xlsx' : 'csv', 'filename' => "collegeportal_{$handler->type()}_template.".($handler->type() === 'employees' ? 'xlsx' : 'csv'), 'headers' => $handler->templateHeaders(), 'example' => array_combine($handler->templateHeaders(), $handler->templateExample())],
                'fields' => collect($fields)->map(fn ($field, $key) => ['value' => $key, 'label' => $field['label'], 'required' => $field['required'], 'example' => $this->fieldExample($handler, $key)])->values()->all(),
            ];
        })->values()->all();
    }

    private function handler(string $dataType): ImportHandlerInterface
    {
        if (!isset($this->handlers[$dataType])) { throw new RuntimeException('Неизвестный тип данных для импорта.'); }
        return $this->handlers[$dataType];
    }

    private function assertKnownType(string $dataType): void { $this->handler($dataType); }
    private function assertKnownMode(string $mode): void { if (!in_array($mode, [self::MODE_CREATE, self::MODE_UPDATE, self::MODE_SKIP_DUPLICATES], true)) { throw new RuntimeException('Неизвестный режим импорта.'); } }
    private function assertSupportedFile(UploadedFile $file): void { if (!in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt', 'xlsx'], true)) { throw new RuntimeException('Поддерживаются только CSV и XLSX файлы.'); } }

    private function parseStoredFile(string $storedPath, ?string $filename): array
    {
        $path = Storage::disk('local')->path($storedPath);
        return strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION)) === 'xlsx' ? $this->parseXlsx($path) : $this->parseCsv($path);
    }

    private function parseCsv(string $path): array
    {
        $sample = file_get_contents($path, false, null, 0, 4096) ?: '';
        $delimiter = $this->detectDelimiter($sample);
        $handle = fopen($path, 'r');
        if (!$handle) { throw new RuntimeException('Не удалось открыть файл импорта.'); }
        // Excel сохраняет CSV с BOM, и снимать его после разбора поздно: BOM стоит
        // перед открывающей кавычкой, поэтому fgetcsv не считает первое поле
        // закавыченным и первый заголовок приезжает вместе с кавычками — а значит
        // не совпадает ни с одним псевдонимом и первая колонка молча теряется.
        if (fread($handle, 3) !== "\xEF\xBB\xBF") { rewind($handle); }
        $headers = $this->normalizeHeaders(fgetcsv($handle, 0, $delimiter) ?: []);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $assoc = [];
            foreach ($headers as $index => $header) { $assoc[$header] = trim((string) ($row[$index] ?? '')); }
            if (count(array_filter($assoc, fn ($value) => $value !== '')) > 0) { $rows[] = $assoc; }
        }
        fclose($handle);
        return ['headers' => $headers, 'rows' => $rows];
    }

    private function parseXlsx(string $path): array
    {
        try { $rawRows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false); }
        catch (\Throwable) { throw new RuntimeException('Не удалось открыть XLSX файл.'); }
        if ($rawRows === []) { return ['headers' => [], 'rows' => []]; }
        $headers = $this->normalizeHeaders($rawRows[0]);
        $rows = [];
        foreach (array_slice($rawRows, 1) as $rawRow) {
            $assoc = [];
            foreach ($headers as $index => $header) { $assoc[$header] = trim((string) ($rawRow[$index] ?? '')); }
            if (count(array_filter($assoc, fn ($value) => $value !== '')) > 0) { $rows[] = $assoc; }
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    private function detectDelimiter(string $sample): string { $delimiters = [';' => substr_count($sample, ';'), ',' => substr_count($sample, ','), "\t" => substr_count($sample, "\t")]; arsort($delimiters); return array_key_first($delimiters) ?: ';'; }
    private function normalizeHeaders(array $headers): array { return array_values(array_map(fn ($header) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $header)), $headers)); }
    private function normalizeKey(string $value): string { return mb_strtolower(trim(str_replace([' ', '-', '_'], '', $value))); }

    private function suggestMapping(string $dataType, array $headers): array
    {
        $handler = $this->handler($dataType);
        $mapping = [];
        foreach ($handler->fields() as $fieldKey => $field) {
            $mapping[$fieldKey] = null;
            foreach ($headers as $header) {
                if (in_array($this->normalizeKey($header), array_map(fn ($alias) => $this->normalizeKey($alias), $field['aliases']), true)) { $mapping[$fieldKey] = $header; break; }
            }
        }
        return $mapping;
    }

    private function validateRows(string $dataType, array $mapping, array $rows, int $limit): array
    {
        $handler = $this->handler($dataType); $errors = []; $errorCount = 0;
        foreach ($rows as $index => $row) {
            $prepared = $this->prepareData($handler, $this->mappedRow($mapping, $row));
            $validator = Validator::make($prepared, $handler->rules());
            if ($validator->fails()) { $errorCount++; foreach ($this->rowValidationErrors($handler, $validator, $mapping, $row, $prepared, $index + 2) as $error) { if (count($errors) < $limit) { $errors[] = $error; } } continue; }
            foreach ($handler->businessValidationErrors($prepared) as $field => $messages) { $errorCount++; if (count($errors) < $limit) { $errors[] = $this->rowError($handler, $mapping, $row, $prepared, $index + 2, implode('; ', $messages), $field, $messages); } }
        }
        return ['errors' => $errors, 'error_count' => $errorCount];
    }

    private function importRows(string $dataType, array $mapping, array $rows, string $mode): array
    {
        $handler = $this->handler($dataType); $created = 0; $updated = 0; $skipped = 0; $errors = [];
        foreach ($rows as $index => $row) {
            $prepared = $this->prepareData($handler, $this->mappedRow($mapping, $row));
            $validator = Validator::make($prepared, $handler->rules());
            if ($validator->fails()) { array_push($errors, ...$this->rowValidationErrors($handler, $validator, $mapping, $row, $prepared, $index + 2)); continue; }
            try {
                $businessErrors = $handler->businessValidationErrors($prepared);
                if ($businessErrors !== []) { foreach ($businessErrors as $field => $messages) { $errors[] = $this->rowError($handler, $mapping, $row, $prepared, $index + 2, implode('; ', $messages), $field, $messages); } continue; }
                $result = $handler->import($prepared, $mode);
                $created += $result === 'created' ? 1 : 0; $updated += $result === 'updated' ? 1 : 0; $skipped += $result === 'skipped' ? 1 : 0;
            } catch (\Throwable $exception) { $field = str_contains($exception->getMessage(), 'Дубликат') ? ($handler->keyFields()[0] ?? null) : null; $errors[] = $this->rowError($handler, $mapping, $row, $prepared, $index + 2, $exception->getMessage(), $field); }
        }
        return ['total_rows' => count($rows), 'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'error_count' => count($errors), 'errors' => array_slice($errors, 0, 200)];
    }

    private function rowValidationErrors(ImportHandlerInterface $handler, $validator, array $mapping, array $sourceRow, array $prepared, int $rowNumber): array
    { $errors = []; foreach ($validator->errors()->messages() as $field => $messages) { $errors[] = $this->rowError($handler, $mapping, $sourceRow, $prepared, $rowNumber, implode('; ', $messages), $field, $messages); } return $errors; }

    private function rowError(ImportHandlerInterface $handler, array $mapping, array $sourceRow, array $prepared, int $rowNumber, string $reason, ?string $field = null, array $messages = []): array
    { $fields = $handler->fields(); $header = $field ? ($mapping[$field] ?? null) : null; $column = $header ?: ($field ? ($fields[$field]['label'] ?? $field) : 'Строка'); $value = $header ? ($sourceRow[$header] ?? null) : ($field ? ($prepared[$field] ?? null) : null); return ['row' => $rowNumber, 'field' => $field, 'column' => $column, 'reason' => $reason, 'value' => $value, 'errors' => $messages ?: [$reason], 'data' => $prepared]; }

    private function csvContent(array $rows): string { return CsvExport::toString($rows); }
    private function fieldExample(ImportHandlerInterface $handler, string $field): ?string { $headers = $handler->templateHeaders(); $example = $handler->templateExample(); $label = $handler->fields()[$field]['label'] ?? null; $index = array_search($label, $headers, true); if ($index === false && $field === 'name') { $index = array_search($handler->type() === 'groups' ? 'Группа' : ($handler->type() === 'subjects' ? 'Дисциплина' : 'Аудитория'), $headers, true); } if ($index === false && $field === 'number') { $index = array_search('Аудитория', $headers, true); } return $index === false ? null : ($example[$index] ?? null); }
    private function mappedRow(array $mapping, array $row): array { $data = []; foreach ($mapping as $field => $header) { $data[$field] = $header ? ($row[$header] ?? null) : null; } return $data; }
    private function prepareData(ImportHandlerInterface $handler, array $data): array { $data = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $data); return $handler->prepare($data); }
}
