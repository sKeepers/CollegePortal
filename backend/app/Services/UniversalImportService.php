<?php

namespace App\Services;

use App\DTO\ScheduleLessonData;
use App\Models\ApplicantApplication;
use App\Models\Curriculum;
use App\Models\CurriculumItem;
use App\Models\Classroom;
use App\Models\EducationProgram;
use App\Models\Group;
use App\Models\ScheduleLesson;
use App\Models\ImportJob;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingLoad;
use App\Models\TeachingLoadItem;
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

    public function __construct(
        private readonly AutoCodeService $autoCodeService,
        private readonly ScheduleLessonService $scheduleLessonService,
    ) {
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
        $this->assertKnownType($dataType);

        $headers = $this->templateHeaders($dataType);
        $example = $this->templateExample($dataType);

        return [
            'filename' => "collegeportal_{$dataType}_template.csv",
            'content' => $this->csvContent([$headers, $example]),
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
            'curricula' => [
                'label' => 'Учебные планы', 'model' => Curriculum::class, 'key' => ['curriculum_code'],
                'fields' => [
                    'curriculum_code' => ['label' => 'Код учебного плана', 'required' => false, 'aliases' => ['код учебного плана', 'код плана', 'curriculum_code', 'code']],
                    'curriculum_name' => ['label' => 'Учебный план', 'required' => true, 'aliases' => ['учебный план', 'название плана', 'curriculum_name', 'name']],
                    'education_program_id' => ['label' => 'ID программы', 'required' => false, 'aliases' => ['education_program_id', 'id программы']],
                    'education_program_name' => ['label' => 'Образовательная программа', 'required' => false, 'aliases' => ['образовательная программа', 'программа', 'education_program_name', 'program_name']],
                    'year_start' => ['label' => 'Год начала', 'required' => true, 'aliases' => ['год начала', 'год набора', 'year_start']],
                    'status' => ['label' => 'Статус', 'required' => false, 'aliases' => ['статус', 'status']],
                    'description' => ['label' => 'Описание', 'required' => false, 'aliases' => ['описание', 'description']],
                    'subject_id' => ['label' => 'ID дисциплины', 'required' => false, 'aliases' => ['subject_id', 'id дисциплины']],
                    'subject_code' => ['label' => 'Код дисциплины', 'required' => false, 'aliases' => ['код дисциплины', 'subject_code']],
                    'subject_name' => ['label' => 'Дисциплина', 'required' => false, 'aliases' => ['дисциплина', 'subject_name']],
                    'course' => ['label' => 'Курс', 'required' => true, 'aliases' => ['курс', 'course']],
                    'semester' => ['label' => 'Семестр', 'required' => true, 'aliases' => ['семестр', 'semester']],
                    'hours_total' => ['label' => 'Часы', 'required' => true, 'aliases' => ['часы', 'hours_total', 'hours']],
                    'control_form' => ['label' => 'Форма контроля', 'required' => false, 'aliases' => ['форма контроля', 'control_form']],
                    'sort_order' => ['label' => 'Порядок', 'required' => false, 'aliases' => ['порядок', 'sort_order']],
                ],
            ],
            'teaching-load' => [
                'label' => 'Нагрузка преподавателей', 'model' => TeachingLoad::class, 'key' => ['academic_year', 'teacher_id'],
                'fields' => [
                    'academic_year' => ['label' => 'Учебный год', 'required' => true, 'aliases' => ['учебный год', 'academic_year']],
                    'teacher_id' => ['label' => 'ID преподавателя', 'required' => false, 'aliases' => ['teacher_id', 'id преподавателя']],
                    'teacher_name' => ['label' => 'Преподаватель', 'required' => false, 'aliases' => ['преподаватель', 'teacher', 'teacher_name']],
                    'status' => ['label' => 'Статус', 'required' => false, 'aliases' => ['статус', 'status']],
                    'description' => ['label' => 'Описание', 'required' => false, 'aliases' => ['описание', 'description']],
                    'subject_id' => ['label' => 'ID дисциплины', 'required' => false, 'aliases' => ['subject_id', 'id дисциплины']],
                    'subject_code' => ['label' => 'Код дисциплины', 'required' => false, 'aliases' => ['код дисциплины', 'subject_code']],
                    'subject_name' => ['label' => 'Дисциплина', 'required' => false, 'aliases' => ['дисциплина', 'subject_name']],
                    'group_id' => ['label' => 'ID группы', 'required' => false, 'aliases' => ['group_id', 'id группы']],
                    'group_name' => ['label' => 'Группа', 'required' => false, 'aliases' => ['группа', 'group', 'group_name']],
                    'semester' => ['label' => 'Семестр', 'required' => true, 'aliases' => ['семестр', 'semester']],
                    'hours_total' => ['label' => 'Часы', 'required' => true, 'aliases' => ['часы', 'hours_total', 'hours']],
                    'load_type' => ['label' => 'Тип нагрузки', 'required' => false, 'aliases' => ['тип нагрузки', 'load_type']],
                    'sort_order' => ['label' => 'Порядок', 'required' => false, 'aliases' => ['порядок', 'sort_order']],
                ],
            ],
            'schedule' => [
                'label' => 'Расписание', 'model' => ScheduleLesson::class, 'key' => ['lesson_date', 'starts_at', 'group_id', 'subject_id', 'teacher_id'],
                'fields' => [
                    'lesson_date' => ['label' => 'Дата', 'required' => true, 'aliases' => ['дата', 'lesson_date']],
                    'starts_at' => ['label' => 'Время начала', 'required' => true, 'aliases' => ['время начала', 'starts_at', 'начало']],
                    'ends_at' => ['label' => 'Время окончания', 'required' => true, 'aliases' => ['время окончания', 'ends_at', 'окончание']],
                    'group_id' => ['label' => 'ID группы', 'required' => false, 'aliases' => ['group_id', 'id группы']],
                    'group_name' => ['label' => 'Группа', 'required' => false, 'aliases' => ['группа', 'group', 'group_name']],
                    'teacher_id' => ['label' => 'ID преподавателя', 'required' => false, 'aliases' => ['teacher_id', 'id преподавателя']],
                    'teacher_name' => ['label' => 'Преподаватель', 'required' => false, 'aliases' => ['преподаватель', 'teacher', 'teacher_name']],
                    'subject_id' => ['label' => 'ID дисциплины', 'required' => false, 'aliases' => ['subject_id', 'id дисциплины']],
                    'subject_code' => ['label' => 'Код дисциплины', 'required' => false, 'aliases' => ['код дисциплины', 'subject_code']],
                    'subject_name' => ['label' => 'Дисциплина', 'required' => false, 'aliases' => ['дисциплина', 'subject_name']],
                    'classroom_id' => ['label' => 'ID аудитории', 'required' => false, 'aliases' => ['classroom_id', 'id аудитории']],
                    'classroom_number' => ['label' => 'Аудитория', 'required' => false, 'aliases' => ['аудитория', 'classroom', 'classroom_number']],
                    'classroom_building' => ['label' => 'Корпус', 'required' => false, 'aliases' => ['корпус', 'building', 'classroom_building']],
                    'lesson_type' => ['label' => 'Тип занятия', 'required' => false, 'aliases' => ['тип занятия', 'lesson_type']],
                    'topic' => ['label' => 'Тема', 'required' => false, 'aliases' => ['тема', 'topic']],
                ],
            ],
        ];
    }

    private function targetsForFrontend(): array
    {
        return collect($this->targets())->map(function ($target, $key) {
            $requiredFields = collect($target['fields'])
                ->filter(fn ($field) => $field['required'])
                ->map(fn ($field, $fieldKey) => ['value' => $fieldKey, 'label' => $field['label']])
                ->values()
                ->all();
            $fieldLabels = collect($target['fields'])->mapWithKeys(fn ($field, $fieldKey) => [$fieldKey => $field['label']])->all();
            $headers = $this->templateHeaders($key);
            $example = $this->templateExample($key);

            return [
                'value' => $key,
                'label' => $target['label'],
                'key_fields' => $target['key'],
                'key_field_labels' => array_values(array_map(fn ($field) => $fieldLabels[$field] ?? $field, $target['key'])),
                'required_fields' => $requiredFields,
                'template' => [
                    'format' => 'csv',
                    'filename' => "collegeportal_{$key}_template.csv",
                    'headers' => $headers,
                    'example' => array_combine($headers, $example),
                ],
                'fields' => collect($target['fields'])->map(fn ($field, $fieldKey) => [
                    'value' => $fieldKey,
                    'label' => $field['label'],
                    'required' => $field['required'],
                    'example' => $this->fieldExample($key, $fieldKey),
                ])->values()->all(),
            ];
        })->values()->all();
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
                foreach ($this->rowValidationErrors($dataType, $validator, $mapping, $row, $prepared, $index + 2) as $error) {
                    if (count($errors) < $limit) {
                        $errors[] = $error;
                    }
                }
                continue;
            }
            foreach ($this->businessValidationErrors($dataType, $mapping, $row, $prepared, $index + 2) as $error) {
                $errorCount++;
                if (count($errors) < $limit) {
                    $errors[] = $error;
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
                array_push($errors, ...$this->rowValidationErrors($dataType, $validator, $mapping, $row, $prepared, $index + 2));
                continue;
            }
            try {
                foreach ($this->businessValidationErrors($dataType, $mapping, $row, $prepared, $index + 2) as $error) {
                    $errors[] = $error;
                    continue 2;
                }
                if ($this->isCompositeImport($dataType)) {
                    $result = $this->importCompositeRow($dataType, $prepared, $mode);
                    $created += $result === 'created' ? 1 : 0;
                    $updated += $result === 'updated' ? 1 : 0;
                    $skipped += $result === 'skipped' ? 1 : 0;
                    continue;
                }
                $existing = $this->findExisting($dataType, $prepared);
                if ($mode === self::MODE_UPDATE) {
                    if (!$existing) { $skipped++; continue; }
                    $existing->update($this->payload($dataType, $prepared, true));
                    $updated++;
                    continue;
                }
                if ($existing) {
                    if ($mode === self::MODE_SKIP_DUPLICATES) { $skipped++; continue; }
                    $errors[] = $this->rowError($dataType, $mapping, $row, $prepared, $index + 2, 'Дубликат по ключевому полю.', $this->targets()[$dataType]['key'][0] ?? null);
                    continue;
                }
                $modelClass = $this->targets()[$dataType]['model'];
                $modelClass::create($this->payload($dataType, $prepared));
                $created++;
            } catch (\Throwable $exception) {
                $errors[] = $this->rowError($dataType, $mapping, $row, $prepared, $index + 2, $exception->getMessage());
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


    private function isCompositeImport(string $dataType): bool
    {
        return in_array($dataType, ['curricula', 'teaching-load', 'schedule'], true);
    }

    private function importCompositeRow(string $dataType, array $data, string $mode): string
    {
        return match ($dataType) {
            'curricula' => $this->importCurriculumRow($data, $mode),
            'teaching-load' => $this->importTeachingLoadRow($data, $mode),
            'schedule' => $this->importScheduleRow($data, $mode),
            default => 'skipped',
        };
    }

    private function importCurriculumRow(array $data, string $mode): string
    {
        $curriculum = Curriculum::where('code', $data['curriculum_code'])->first()
            ?: Curriculum::where('education_program_id', $data['education_program_id'])->where('name', $data['curriculum_name'])->where('year_start', $data['year_start'])->first();

        if ($mode === self::MODE_UPDATE && !$curriculum) {
            return 'skipped';
        }
        $curriculumPayload = [
            'code' => $data['curriculum_code'],
            'education_program_id' => $data['education_program_id'],
            'name' => $data['curriculum_name'],
            'year_start' => $data['year_start'],
            'status' => $data['status'] ?: 'draft',
            'description' => $data['description'] ?? null,
        ];
        $curriculum = $curriculum ? tap($curriculum)->update($curriculumPayload) : Curriculum::create($curriculumPayload);

        $item = CurriculumItem::where('curriculum_id', $curriculum->id)
            ->where('subject_id', $data['subject_id'])
            ->where('course', $data['course'])
            ->where('semester', $data['semester'])
            ->first();

        if ($item) {
            if ($mode === self::MODE_SKIP_DUPLICATES) {
                return 'skipped';
            }
            if ($mode === self::MODE_CREATE) {
                throw new RuntimeException('Строка учебного плана уже существует.');
            }
        }

        $payload = [
            'curriculum_id' => $curriculum->id,
            'subject_id' => $data['subject_id'],
            'course' => $data['course'],
            'semester' => $data['semester'],
            'hours_total' => $data['hours_total'],
            'control_form' => $data['control_form'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ];
        if ($item) {
            $item->update($payload);
            return 'updated';
        }
        CurriculumItem::create($payload);
        return 'created';
    }

    private function importTeachingLoadRow(array $data, string $mode): string
    {
        $load = TeachingLoad::where('academic_year', $data['academic_year'])->where('teacher_id', $data['teacher_id'])->first();
        if ($mode === self::MODE_UPDATE && !$load) {
            return 'skipped';
        }

        $loadPayload = [
            'academic_year' => $data['academic_year'],
            'teacher_id' => $data['teacher_id'],
            'status' => $data['status'] ?: 'draft',
            'description' => $data['description'] ?? null,
        ];
        $load = $load ? tap($load)->update($loadPayload) : TeachingLoad::create($loadPayload);

        $item = TeachingLoadItem::where('teaching_load_id', $load->id)
            ->where('subject_id', $data['subject_id'])
            ->where('group_id', $data['group_id'])
            ->where('semester', $data['semester'])
            ->where('load_type', $data['load_type'])
            ->first();

        if ($item) {
            if ($mode === self::MODE_SKIP_DUPLICATES) {
                return 'skipped';
            }
            if ($mode === self::MODE_CREATE) {
                throw new RuntimeException('Строка нагрузки уже существует.');
            }
        }

        $payload = [
            'teaching_load_id' => $load->id,
            'subject_id' => $data['subject_id'],
            'group_id' => $data['group_id'],
            'semester' => $data['semester'],
            'hours_total' => $data['hours_total'],
            'load_type' => $data['load_type'],
            'sort_order' => $data['sort_order'] ?? 0,
        ];
        if ($item) {
            $item->update($payload);
            return 'updated';
        }
        TeachingLoadItem::create($payload);
        return 'created';
    }

    private function importScheduleRow(array $data, string $mode): string
    {
        $lesson = $this->findExisting('schedule', $data);
        if ($mode === self::MODE_UPDATE && !$lesson) {
            return 'skipped';
        }
        if ($lesson) {
            if ($mode === self::MODE_SKIP_DUPLICATES) {
                return 'skipped';
            }
            if ($mode === self::MODE_CREATE) {
                throw new RuntimeException('Занятие уже существует.');
            }
        }

        $lessonData = ScheduleLessonData::fromArray($this->schedulePayload($data));
        if ($lesson) {
            $this->scheduleLessonService->update($lesson, $lessonData);
            return 'updated';
        }
        $this->scheduleLessonService->create($lessonData);
        return 'created';
    }

    private function schedulePayload(array $data): array
    {
        return [
            'group_id' => $data['group_id'],
            'teacher_id' => $data['teacher_id'],
            'subject_id' => $data['subject_id'],
            'classroom_id' => $data['classroom_id'] ?? null,
            'lesson_date' => $data['lesson_date'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'lesson_type' => $data['lesson_type'] ?: 'lesson',
            'topic' => $data['topic'] ?? null,
        ];
    }

    private function businessValidationErrors(string $dataType, array $mapping, array $sourceRow, array $prepared, int $rowNumber): array
    {
        if ($dataType !== 'schedule') {
            return [];
        }

        $existing = $this->findExisting('schedule', $prepared);
        $conflicts = $this->scheduleConflictMessages($prepared, $existing?->id);
        $errors = [];
        foreach ($conflicts as $field => $messages) {
            $errors[] = $this->rowError($dataType, $mapping, $sourceRow, $prepared, $rowNumber, implode('; ', $messages), $field, $messages);
        }

        return $errors;
    }

    private function scheduleConflictMessages(array $data, ?int $ignoreLessonId = null): array
    {
        $errors = [];
        if ($this->scheduleHasConflict('group_id', (int) $data['group_id'], $data, $ignoreLessonId)) {
            $errors['group_id'][] = 'Группа уже занята в это время.';
        }
        if ($this->scheduleHasConflict('teacher_id', (int) $data['teacher_id'], $data, $ignoreLessonId)) {
            $errors['teacher_id'][] = 'Преподаватель уже ведет занятие в это время.';
        }
        if (!empty($data['classroom_id']) && $this->scheduleHasConflict('classroom_id', (int) $data['classroom_id'], $data, $ignoreLessonId)) {
            $errors['classroom_id'][] = 'Аудитория уже занята в это время.';
        }

        return $errors;
    }

    private function scheduleHasConflict(string $column, int $value, array $data, ?int $ignoreLessonId): bool
    {
        return ScheduleLesson::query()
            ->where($column, $value)
            ->whereDate('lesson_date', $data['lesson_date'])
            ->where('starts_at', '<', $data['ends_at'])
            ->where('ends_at', '>', $data['starts_at'])
            ->when($ignoreLessonId, fn ($query) => $query->whereKeyNot($ignoreLessonId))
            ->exists();
    }

    private function rowValidationErrors(string $dataType, $validator, array $mapping, array $sourceRow, array $prepared, int $rowNumber): array
    {
        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            $errors[] = $this->rowError($dataType, $mapping, $sourceRow, $prepared, $rowNumber, implode('; ', $messages), $field, $messages);
        }
        return $errors;
    }

    private function rowError(string $dataType, array $mapping, array $sourceRow, array $prepared, int $rowNumber, string $reason, ?string $field = null, array $messages = []): array
    {
        $target = $this->targets()[$dataType];
        $header = $field ? ($mapping[$field] ?? null) : null;
        $column = $header ?: ($field ? ($target['fields'][$field]['label'] ?? $field) : 'Строка');
        $value = $header ? ($sourceRow[$header] ?? null) : ($field ? ($prepared[$field] ?? null) : null);

        return [
            'row' => $rowNumber,
            'field' => $field,
            'column' => $column,
            'reason' => $reason,
            'value' => $value,
            'errors' => $messages ?: [$reason],
            'data' => $prepared,
        ];
    }

    private function csvContent(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }
        rewind($handle);
        return stream_get_contents($handle) ?: '';
    }

    private function templateHeaders(string $dataType): array
    {
        return match ($dataType) {
            'students' => ['Фамилия', 'Имя', 'Отчество', 'Группа', 'Дата рождения', 'Телефон', 'Email', 'Статус', 'Дата зачисления'],
            'groups' => ['Группа', 'Специальность', 'Курс', 'Год набора', 'Образовательная программа'],
            'teachers' => ['Фамилия', 'Имя', 'Отчество', 'Телефон', 'Email', 'Должность', 'Отделение', 'Активен'],
            'subjects' => ['Дисциплина', 'Код', 'Отделение', 'Описание'],
            'classrooms' => ['Аудитория', 'Корпус', 'Этаж', 'Вместимость', 'Тип', 'Описание'],
            'applicants' => ['Фамилия', 'Имя', 'Отчество', 'Образовательная программа', 'Дата рождения', 'Телефон', 'Email', 'База', 'Статус', 'Дата подачи', 'Комментарий'],
            'curricula' => ['Код учебного плана', 'Учебный план', 'Образовательная программа', 'Год начала', 'Статус', 'Дисциплина', 'Код дисциплины', 'Курс', 'Семестр', 'Часы', 'Форма контроля', 'Порядок'],
            'teaching-load' => ['Учебный год', 'Преподаватель', 'Статус', 'Дисциплина', 'Код дисциплины', 'Группа', 'Семестр', 'Часы', 'Тип нагрузки', 'Порядок'],
            'schedule' => ['Дата', 'Время начала', 'Время окончания', 'Группа', 'Преподаватель', 'Дисциплина', 'Код дисциплины', 'Аудитория', 'Корпус', 'Тип занятия', 'Тема'],
            default => [],
        };
    }

    private function templateExample(string $dataType): array
    {
        return match ($dataType) {
            'students' => ['Иванов', 'Дмитрий', 'Сергеевич', 'ИСП-101', '12.05.2009', '+79990000002', 'student@example.test', 'active', '01.09.2026'],
            'groups' => ['ИСП-101', 'Инструментальное исполнительство', '1', '2026', 'Фортепиано'],
            'teachers' => ['Петрова', 'Анна', 'Викторовна', '+79990000010', 'teacher@example.test', 'Преподаватель', 'Музыкальное отделение', 'да'],
            'subjects' => ['История музыки', 'MUS-101', 'Музыкальное отделение', 'Базовая дисциплина'],
            'classrooms' => ['201', 'Главный корпус', '2', '24', 'Учебная аудитория', 'Фортепианный класс'],
            'applicants' => ['Смирнова', 'Алина', 'Олеговна', 'Фортепиано', '03.04.2010', '+79990000020', 'applicant@example.test', '9 классов', 'new', '20.06.2026', 'Оригиналы документов ожидаются'],
            'curricula' => ['УП-ФО-2026', 'Учебный план Фортепиано 2026', 'Фортепиано', '2026', 'draft', 'Специальность', 'SPEC-001', '1', '1', '144', 'Экзамен', '10'],
            'teaching-load' => ['2026/2027', 'Петрова Анна Викторовна', 'draft', 'Специальность', 'SPEC-001', 'ИСП-101', '1', '72', 'Аудиторная', '10'],
            'schedule' => ['01.09.2026', '09:00', '10:30', 'ИСП-101', 'Петрова Анна Викторовна', 'Специальность', 'SPEC-001', '201', 'Главный корпус', 'Практическое', 'Вводное занятие'],
            default => [],
        };
    }

    private function fieldExample(string $dataType, string $field): ?string
    {
        $headers = $this->templateHeaders($dataType);
        $example = $this->templateExample($dataType);
        $target = $this->targets()[$dataType];
        $label = $target['fields'][$field]['label'] ?? null;
        $index = array_search($label, $headers, true);
        if ($index === false && $field === 'name') {
            $index = array_search($dataType === 'groups' ? 'Группа' : ($dataType === 'subjects' ? 'Дисциплина' : 'Аудитория'), $headers, true);
        }
        if ($index === false && $field === 'number') {
            $index = array_search('Аудитория', $headers, true);
        }
        if ($index === false) {
            return null;
        }
        return $example[$index] ?? null;
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
        foreach (['birth_date', 'enrollment_date', 'submitted_at', 'lesson_date'] as $field) {
            if (!empty($data[$field])) { $data[$field] = $this->normalizeDate((string) $data[$field]); }
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            if (!empty($data[$field])) { $data[$field] = $this->normalizeTime((string) $data[$field]); }
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
        if ($dataType === 'curricula') {
            $data['education_program_id'] = $this->resolveProgramId($data['education_program_id'] ?? null, $data['education_program_name'] ?? null);
            $data['subject_id'] = $this->resolveSubjectId($data['subject_id'] ?? null, $data['subject_code'] ?? null, $data['subject_name'] ?? null);
            $data['curriculum_code'] = $data['curriculum_code'] ?: $this->autoCodeService->curriculumCode($data['curriculum_name'] ?? null);
            $data['status'] = $data['status'] ?: 'draft';
            $data['sort_order'] = $data['sort_order'] ?: 0;
        }
        if ($dataType === 'teaching-load') {
            $data['teacher_id'] = $this->resolveTeacherId($data['teacher_id'] ?? null, $data['teacher_name'] ?? null);
            $data['subject_id'] = $this->resolveSubjectId($data['subject_id'] ?? null, $data['subject_code'] ?? null, $data['subject_name'] ?? null);
            $data['group_id'] = $this->resolveGroupId($data['group_id'] ?? null, $data['group_name'] ?? null);
            $data['status'] = $data['status'] ?: 'draft';
            $data['load_type'] = $data['load_type'] ?: 'Аудиторная';
            $data['sort_order'] = $data['sort_order'] ?: 0;
        }
        if ($dataType === 'schedule') {
            $data['group_id'] = $this->resolveGroupId($data['group_id'] ?? null, $data['group_name'] ?? null);
            $data['teacher_id'] = $this->resolveTeacherId($data['teacher_id'] ?? null, $data['teacher_name'] ?? null);
            $data['subject_id'] = $this->resolveSubjectId($data['subject_id'] ?? null, $data['subject_code'] ?? null, $data['subject_name'] ?? null);
            $data['classroom_id'] = $this->resolveClassroomId($data['classroom_id'] ?? null, $data['classroom_number'] ?? null, $data['classroom_building'] ?? null);
            $data['lesson_type'] = $data['lesson_type'] ?: 'lesson';
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
            'curricula' => ['curriculum_code' => ['nullable', 'string', 'max:100'], 'curriculum_name' => ['required', 'string', 'max:255'], 'education_program_id' => ['required', 'integer', 'exists:education_programs,id'], 'year_start' => ['required', 'integer', 'min:2000', 'max:2100'], 'status' => ['nullable', 'string', 'max:50'], 'description' => ['nullable', 'string'], 'subject_id' => ['required', 'integer', 'exists:subjects,id'], 'course' => ['required', 'integer', 'min:1', 'max:6'], 'semester' => ['required', 'integer', 'min:1', 'max:12'], 'hours_total' => ['required', 'integer', 'min:0', 'max:5000'], 'control_form' => ['nullable', 'string', 'max:255'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535']],
            'teaching-load' => ['academic_year' => ['required', 'string', 'max:20'], 'teacher_id' => ['required', 'integer', 'exists:teachers,id'], 'status' => ['nullable', 'string', 'max:50'], 'description' => ['nullable', 'string'], 'subject_id' => ['required', 'integer', 'exists:subjects,id'], 'group_id' => ['required', 'integer', 'exists:groups,id'], 'semester' => ['required', 'integer', 'min:1', 'max:12'], 'hours_total' => ['required', 'integer', 'min:0', 'max:5000'], 'load_type' => ['required', 'string', 'max:255'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535']],
            'schedule' => ['lesson_date' => ['required', 'date'], 'starts_at' => ['required', 'date_format:H:i'], 'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'], 'group_id' => ['required', 'integer', 'exists:groups,id'], 'teacher_id' => ['required', 'integer', 'exists:teachers,id'], 'subject_id' => ['required', 'integer', 'exists:subjects,id'], 'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'], 'lesson_type' => ['required', 'string', 'max:255'], 'topic' => ['nullable', 'string', 'max:255']],
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
            'curricula' => Curriculum::where('code', $data['curriculum_code'] ?? null)->first(),
            'teaching-load' => TeachingLoad::where('academic_year', $data['academic_year'] ?? null)->where('teacher_id', $data['teacher_id'] ?? null)->first(),
            'schedule' => ScheduleLesson::where('lesson_date', $data['lesson_date'] ?? null)->where('starts_at', $data['starts_at'] ?? null)->where('group_id', $data['group_id'] ?? null)->where('subject_id', $data['subject_id'] ?? null)->where('teacher_id', $data['teacher_id'] ?? null)->first(),
            default => null,
        };
    }

    private function resolveSubjectId($id, ?string $code, ?string $name): ?int
    {
        if ($id) { return (int) $id; }
        if ($code) { return Subject::where('code', $code)->value('id'); }
        if ($name) { return Subject::where('name', $name)->value('id'); }
        return null;
    }

    private function resolveTeacherId($id, ?string $name): ?int
    {
        if ($id) { return (int) $id; }
        $name = trim((string) $name);
        if ($name === '') { return null; }
        return Teacher::query()
            ->whereRaw("trim(concat_ws(' ', last_name, first_name, middle_name)) = ?", [$name])
            ->orWhereRaw("trim(concat_ws(' ', last_name, first_name)) = ?", [$name])
            ->value('id');
    }

    private function resolveClassroomId($id, ?string $number, ?string $building): ?int
    {
        if ($id) { return (int) $id; }
        if (!$number) { return null; }
        $query = Classroom::where('number', $number);
        if ($building) {
            $query->where('building', $building);
        }
        return $query->value('id');
    }

    private function normalizeTime(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
            [$hour, $minute] = array_slice(explode(':', $value), 0, 2);
            return sprintf('%02d:%02d', (int) $hour, (int) $minute);
        }
        if (is_numeric($value) && (float) $value > 0 && (float) $value < 1) {
            $minutes = (int) round(((float) $value) * 24 * 60);
            return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }
        return $value;
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
