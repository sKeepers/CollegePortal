<?php

namespace App\Services\Import;

use App\Models\ApplicantApplication;
use App\Models\EducationProgram;
use App\Models\ImportJob;
use App\Models\Person;
use App\Services\ApplicantApplicationDocumentService;
use App\Services\Admissions\SnilsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use RuntimeException;

class FisAdmissionsImportHandler
{
    public const SOURCE = 'fis_admissions';

    private const REQUIRED_HEADERS = [
        '№ заявления', 'Статус', 'Дата регистрации', 'ФИО', 'Конкурс', 'Дата рождения', 'СНИЛС',
    ];

    private const HEADER_MAP = [
        'application_number' => '№ заявления',
        'status' => 'Статус',
        'last_checked_at' => 'Дата последней проверки',
        'registered_at' => 'Дата регистрации',
        'fio' => 'ФИО',
        'competition' => 'Конкурс',
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
        'average_score' => 'Средний балл документа об образовании',
        'achievement_score' => 'Балл ИД',
        'ranking_score' => 'Рейтинг',
        'documents_provided' => 'Документы предоставлены',
        'recommended' => 'Рекомендован к зачислению',
    ];

    public function __construct(private readonly ApplicantApplicationDocumentService $documentService, private readonly SnilsService $snils)
    {
    }

    public function analyzePath(string $path): array
    {
        $parsed = $this->parseSpreadsheet($path);

        return [
            'recognized' => $this->isFisExport($parsed['headers']),
            'reader' => $parsed['reader'],
            'sheet' => $parsed['sheet'],
            'header_row' => $parsed['header_row'],
            'headers' => $parsed['headers'],
            'row_count' => count($parsed['rows']),
            'column_count' => count($parsed['headers']),
            'merged_cells_count' => $parsed['merged_cells_count'],
            'missing_required_headers' => array_values(array_diff(self::REQUIRED_HEADERS, $parsed['headers'])),
            'file_hash' => hash_file('sha256', $path),
        ];
    }

    public function dryRunPath(string $path, ?ImportJob $job = null): array
    {
        $parsed = $this->parseSpreadsheet($path);
        $this->assertFisExport($parsed['headers']);

        return $this->evaluateRows($parsed, false, $job);
    }

    public function applyPath(string $path, ImportJob $job): array
    {
        $parsed = $this->parseSpreadsheet($path);
        $this->assertFisExport($parsed['headers']);
        $dryRun = $this->evaluateRows($parsed, false, $job);

        if (($dryRun['critical_errors'] ?? 0) > 0 || ($dryRun['ambiguous_duplicates'] ?? 0) > 0 || ($dryRun['unresolved_competitions'] ?? 0) > 0) {
            throw new RuntimeException('Apply заблокирован: dry-run содержит критические ошибки, неоднозначные дубли или несопоставленные конкурсы.');
        }

        return DB::transaction(fn () => $this->evaluateRows($parsed, true, $job));
    }

    public function dryRunJob(ImportJob $job): array
    {
        return $this->dryRunPath(Storage::disk('local')->path($job->stored_path), $job);
    }

    public function applyJob(ImportJob $job): array
    {
        return $this->applyPath(Storage::disk('local')->path($job->stored_path), $job);
    }

    private function parseSpreadsheet(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Файл ФИС не найден.');
        }

        $reader = IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(false);
        }
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
        $headerRow = $this->detectHeaderRow($sheet, $highestRow, $highestColumnIndex);
        $headers = [];

        for ($column = 1; $column <= $highestColumnIndex; $column++) {
            $headers[] = $this->clean($sheet->getCell([$column, $headerRow])->getFormattedValue());
        }

        $rows = [];
        for ($rowNumber = $headerRow + 1; $rowNumber <= $highestRow; $rowNumber++) {
            $row = ['_row' => $rowNumber];
            $hasData = false;
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $value = $this->cellValue($sheet->getCell([$index + 1, $rowNumber])->getValue(), $sheet->getCell([$index + 1, $rowNumber])->getFormattedValue());
                $row[$header] = $value;
                if ($value !== '') {
                    $hasData = true;
                }
            }
            if ($hasData) {
                $rows[] = $row;
            }
        }

        return [
            'reader' => class_basename($reader),
            'sheet' => $sheet->getTitle(),
            'header_row' => $headerRow,
            'headers' => array_values(array_filter($headers, fn ($header) => $header !== '')),
            'rows' => $rows,
            'merged_cells_count' => count($sheet->getMergeCells()),
        ];
    }

    private function detectHeaderRow($sheet, int $highestRow, int $highestColumnIndex): int
    {
        $bestRow = 1;
        $bestScore = -1;
        for ($row = 1; $row <= min(30, $highestRow); $row++) {
            $score = 0;
            for ($column = 1; $column <= $highestColumnIndex; $column++) {
                $value = $this->normalizeHeader($sheet->getCell([$column, $row])->getFormattedValue());
                foreach (self::REQUIRED_HEADERS as $required) {
                    if ($value === $this->normalizeHeader($required)) {
                        $score += 3;
                    }
                }
                foreach (['заяв', 'фио', 'конкурс', 'снилс', 'рейтинг'] as $needle) {
                    if (str_contains($value, $needle)) {
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

    private function isFisExport(array $headers): bool
    {
        return count(array_diff(self::REQUIRED_HEADERS, $headers)) === 0;
    }

    private function assertFisExport(array $headers): void
    {
        $missing = array_diff(self::REQUIRED_HEADERS, $headers);
        if ($missing !== []) {
            throw new RuntimeException('Файл не похож на экспорт ФИС. Не найдены колонки: '.implode(', ', $missing));
        }
    }

    private function evaluateRows(array $parsed, bool $apply, ?ImportJob $job): array
    {
        $programs = $this->programIndex();
        $summary = $this->emptySummary($parsed, $apply);
        $seenPersonKeys = [];
        $seenApplicationNumbers = [];
        $preview = [];

        foreach ($parsed['rows'] as $sourceRow) {
            $row = $this->normalizeRow($sourceRow);
            $rowNumber = $sourceRow['_row'];
            $summary['status_distribution'][$row['external_status'] ?: 'Без статуса'] = ($summary['status_distribution'][$row['external_status'] ?: 'Без статуса'] ?? 0) + 1;
            $summary['competition_distribution'][$row['competition_name'] ?: 'Без конкурса'] = ($summary['competition_distribution'][$row['competition_name'] ?: 'Без конкурса'] ?? 0) + 1;

            $program = $this->resolveProgram($row['competition_name'], $programs);
            if ($row['snils_error'] !== null) {
                $summary['errors'][] = $this->rowIssue($rowNumber, 'СНИЛС', $row['snils_error'], $row['snils_raw']);
            }
            if ($program) {
                $summary['exact_matched_competitions_map'][$row['competition_name']] = $program->name;
            } else {
                $summary['unresolved_competitions_map'][$row['competition_name'] ?: 'Без конкурса'] = true;
                $summary['errors'][] = $this->rowIssue($rowNumber, 'Конкурс', 'Не найдено точное соответствие образовательной программе.', $row['competition_name']);
            }

            $duplicates = $this->findPersonCandidates($row);
            if ($duplicates->count() > 1) {
                $summary['ambiguous'][] = ['row' => $rowNumber, 'reason' => 'Найдено несколько возможных Person.', 'candidate_ids' => $duplicates->pluck('id')->values()->all()];
            }

            $person = $duplicates->count() === 1 ? $duplicates->first() : null;
            $existingApplication = $this->findExistingApplication($row, $person);
            if ($existingApplication?->person_id && ! $person) {
                $person = $existingApplication->person;
            }

            $personKey = $person ? 'id:'.$person->id : $this->newPersonKey($row);
            $seenPersonKeys[$personKey] = true;
            if ($person) {
                $summary['found_person_ids'][$person->id] = true;
            } else {
                $summary['new_person_keys'][$personKey] = true;
            }

            if ($existingApplication) {
                $summary['applications_to_update']++;
            } else {
                $summary['applications_to_create']++;
            }
            if ($row['external_application_number'] !== '') {
                $applicationNumber = $row['external_application_number'];
                if (isset($seenApplicationNumbers[$applicationNumber])) {
                    $summary['errors'][] = $this->rowIssue(
                        $rowNumber,
                        '№ заявления',
                        'Номер заявления повторяется в файле; первое вхождение находится в строке '.$seenApplicationNumbers[$applicationNumber].'.',
                        $applicationNumber,
                    );
                } else {
                    $seenApplicationNumbers[$applicationNumber] = $rowNumber;
                }
            }

            $preview[] = $this->previewRow($rowNumber, $row, (bool) $program, $person, $existingApplication);

            if ($apply && $program && $duplicates->count() <= 1) {
                $person = $person ?: Person::create($this->personPayload($row));
                if ($person) {
                    $person->fill(array_filter($this->personPayload($row), fn ($value) => $value !== null && $value !== ''))->save();
                }
                $application = $existingApplication ?: new ApplicantApplication();
                $application->fill($this->applicationPayload($row, $program, $person));
                $application->save();
                $this->documentService->ensureDefaultDocuments($application);
                if ($existingApplication) {
                    $summary['updated_count']++;
                } else {
                    $summary['created_count']++;
                }
            }
        }

        $summary['unique_persons'] = count($seenPersonKeys);
        $summary['applications'] = count($seenApplicationNumbers) ?: count($parsed['rows']);
        $summary['found_persons'] = count($summary['found_person_ids']);
        $summary['new_persons'] = count($summary['new_person_keys']);
        $summary['ambiguous_duplicates'] = count($summary['ambiguous']);
        $summary['unique_competitions'] = count($summary['competition_distribution']);
        $summary['exact_matched_competitions'] = count($summary['exact_matched_competitions_map']);
        $summary['unresolved_competitions'] = count($summary['unresolved_competitions_map']);
        $summary['blocked_rows'] = $summary['unresolved_competitions'] > 0 ? count(array_filter($parsed['rows'], fn ($row) => ! $this->resolveProgram($this->clean($row[self::HEADER_MAP['competition']] ?? ''), $programs))) : 0;
        $summary['valid_rows'] = max(0, $summary['total_rows'] - count($summary['errors']) - $summary['ambiguous_duplicates']);
        $summary['critical_errors'] = count($summary['errors']);
        $summary['preview_rows'] = array_slice($preview, 0, 20);
        $summary['warnings'] = $this->warnings($summary, $job);

        unset($summary['found_person_ids'], $summary['new_person_keys']);
        $summary['unresolved_competitions_list'] = array_keys($summary['unresolved_competitions_map']);
        $summary['exact_matched_competitions_list'] = $summary['exact_matched_competitions_map'];
        unset($summary['unresolved_competitions_map'], $summary['exact_matched_competitions_map']);

        return $summary;
    }

    private function emptySummary(array $parsed, bool $apply): array
    {
        return [
            'mode' => $apply ? 'apply' : 'dry_run',
            'reader' => $parsed['reader'],
            'sheet' => $parsed['sheet'],
            'header_row' => $parsed['header_row'],
            'headers' => $parsed['headers'],
            'total_rows' => count($parsed['rows']),
            'valid_rows' => 0,
            'applications' => 0,
            'unique_persons' => 0,
            'found_persons' => 0,
            'new_persons' => 0,
            'applications_to_create' => 0,
            'applications_to_update' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'ambiguous_duplicates' => 0,
            'unique_competitions' => 0,
            'exact_matched_competitions' => 0,
            'unresolved_competitions' => 0,
            'blocked_rows' => 0,
            'critical_errors' => 0,
            'status_distribution' => [],
            'competition_distribution' => [],
            'exact_matched_competitions_map' => [],
            'unresolved_competitions_map' => [],
            'found_person_ids' => [],
            'new_person_keys' => [],
            'ambiguous' => [],
            'errors' => [],
            'warnings' => [],
        ];
    }

    private function normalizeRow(array $row): array
    {
        [$lastName, $firstName, $middleName] = $this->splitFio($this->value($row, 'fio'));

        return [
            'external_application_number' => $this->value($row, 'application_number'),
            'external_status' => $this->value($row, 'status'),
            'external_registered_at' => $this->date($this->raw($row, 'registered_at')),
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'competition_name' => $this->value($row, 'competition'),
            'citizenship' => $this->value($row, 'citizenship'),
            'gender' => $this->normalizeGender($this->value($row, 'gender')),
            'birth_date' => $this->date($this->raw($row, 'birth_date')),
            'place_birth' => $this->value($row, 'place_birth'),
            'address' => trim(implode(', ', array_filter([$this->value($row, 'region'), $this->value($row, 'settlement_type'), $this->value($row, 'address')]))),
            ...$this->normalizedSnils($this->value($row, 'snils')),
            'email' => mb_strtolower($this->value($row, 'email')),
            'certificate_average_score' => $this->decimal($this->value($row, 'average_score')),
            'achievement_score' => $this->decimal($this->value($row, 'achievement_score')),
            'ranking_score' => $this->decimal($this->value($row, 'ranking_score')),
            'documents_provided' => $this->bool($this->value($row, 'documents_provided')),
            'recommended_for_enrollment' => $this->bool($this->value($row, 'recommended')),
            'passport_present' => $this->value($row, 'passport') !== '',
        ];
    }

    private function findPersonCandidates(array $row)
    {
        if ($row['snils']) {
            $bySnils = Person::query()->where('snils_hash', $this->snils->hash($row['snils']))->get();
            if ($bySnils->isEmpty()) {
                $bySnils = Person::query()->where('snils', $row['snils'])->get();
            }
            if ($bySnils->isNotEmpty()) {
                return $bySnils;
            }
        }

        $application = ApplicantApplication::query()
            ->legacy()
            ->with('person')
            ->where('external_source', self::SOURCE)
            ->where('external_application_number', $row['external_application_number'])
            ->first();

        if ($application?->person) {
            return collect([$application->person]);
        }

        if ($row['last_name'] && $row['first_name'] && $row['birth_date']) {
            return Person::query()
                ->where('last_name', $row['last_name'])
                ->where('first_name', $row['first_name'])
                ->where('birth_date', $row['birth_date'])
                ->where(function ($query) use ($row): void {
                    $query->where('middle_name', $row['middle_name'])->orWhereNull('middle_name');
                })
                ->limit(10)
                ->get();
        }

        return collect();
    }

    private function findExistingApplication(array $row, ?Person $person): ?ApplicantApplication
    {
        $query = ApplicantApplication::query()->legacy()->with('person');
        if ($row['external_application_number'] !== '') {
            $external = (clone $query)->where('external_source', self::SOURCE)->where('external_application_number', $row['external_application_number'])->first();
            if ($external) {
                return $external;
            }
        }

        if ($person && $row['competition_name'] && $row['external_registered_at']) {
            return (clone $query)
                ->where('person_id', $person->id)
                ->where('competition_name', $row['competition_name'])
                ->where('submitted_at', $row['external_registered_at'])
                ->first();
        }

        return null;
    }

    private function personPayload(array $row): array
    {
        return [
            'last_name' => $row['last_name'],
            'first_name' => $row['first_name'],
            'middle_name' => $row['middle_name'],
            'birth_date' => $row['birth_date'],
            'gender' => $row['gender'],
            'citizenship' => $row['citizenship'],
            'email' => $row['email'] ?: null,
            'snils' => $row['snils'] ?: null,
            'snils_hash' => $this->snils->hash($row['snils']),
            'place_birth' => $row['place_birth'] ?: null,
            'address' => $row['address'] ?: null,
            'status' => 'active',
        ];
    }

    private function applicationPayload(array $row, EducationProgram $program, Person $person): array
    {
        return [
            'person_id' => $person->id,
            'external_source' => self::SOURCE,
            'external_application_number' => $row['external_application_number'],
            'external_status' => $row['external_status'],
            'external_registered_at' => $row['external_registered_at'],
            'education_program_id' => $program->id,
            'competition_name' => $row['competition_name'],
            'last_name' => $row['last_name'],
            'first_name' => $row['first_name'],
            'middle_name' => $row['middle_name'],
            'birth_date' => $row['birth_date'],
            'email' => $row['email'] ?: null,
            'education_base' => 'after_9',
            'education_form' => $program->study_form,
            'funding_form' => null,
            'status' => $this->status($row['external_status']),
            'submitted_at' => $row['external_registered_at'] ?: now()->toDateString(),
            'certificate_average_score' => $row['certificate_average_score'],
            'achievement_score' => $row['achievement_score'],
            'ranking_score' => $row['ranking_score'],
            'documents_provided' => $row['documents_provided'],
            'recommended_for_enrollment' => $row['recommended_for_enrollment'],
            'fis_raw_data' => [
                'passport_present' => $row['passport_present'],
                'source' => self::SOURCE,
            ],
            'comment' => 'Импортировано из ФИС ГИА и Приема.',
        ];
    }

    private function programIndex(): array
    {
        return EducationProgram::query()->with('specialty')->get()->mapWithKeys(function (EducationProgram $program) {
            $keys = [$this->normalizeComparable($program->name) => $program];
            if ($program->specialty) {
                $keys[$this->normalizeComparable($program->specialty->name)] = $program;
                $keys[$this->normalizeComparable($program->specialty->code.' '.$program->specialty->name)] = $program;
            }
            return $keys;
        })->all();
    }

    private function resolveProgram(string $competition, array $programs): ?EducationProgram
    {
        $normalized = $this->normalizeComparable($competition);
        if (isset($programs[$normalized])) {
            return $programs[$normalized];
        }
        $manual = $this->manualProgram($competition);
        if ($manual) {
            return $manual;
        }

        foreach ($programs as $key => $program) {
            if ($key !== '' && str_contains($normalized, $key)) {
                return $program;
            }
        }
        return null;
    }


    private function manualProgram(string $competition): ?EducationProgram
    {
        $normalized = $this->normalizeComparable($competition);
        if (! str_contains($normalized, '51 02 02') && ! str_contains($normalized, 'скд')) {
            return null;
        }

        $after11 = str_contains($normalized, '11 класс');
        $after9 = str_contains($normalized, '9 класс') || str_contains($normalized, '9 классов');
        $query = EducationProgram::query()
            ->whereHas('specialty', fn ($query) => $query->where('code', '51.02.02'))
            ->orderBy('id');

        if ($after11) {
            $query->where('name', 'like', '%11%');
        } elseif ($after9) {
            $query->where('name', 'like', '%9%');
        }

        return $query->first();
    }

    private function warnings(array $summary, ?ImportJob $job): array
    {
        $warnings = [];
        if ($summary['unresolved_competitions'] > 0) {
            $warnings[] = 'Есть несопоставленные конкурсы. Apply заблокирован до ручного сопоставления.';
        }
        if ($summary['ambiguous_duplicates'] > 0) {
            $warnings[] = 'Есть неоднозначные совпадения Person. Автоматическое связывание не выполняется.';
        }
        if ($job?->file_hash && ImportJob::query()->where('source', self::SOURCE)->where('file_hash', $job->file_hash)->whereKeyNot($job->id)->exists()) {
            $warnings[] = 'Файл с таким SHA-256 уже встречался в истории импорта.';
        }
        $warnings[] = 'Паспортные данные не сохраняются в открытом виде; фиксируется только факт наличия документа.';
        return $warnings;
    }

    private function previewRow(int $rowNumber, array $row, bool $programMatched, ?Person $person, ?ApplicantApplication $application): array
    {
        return [
            'row' => $rowNumber,
            'application_number' => $row['external_application_number'],
            'fio' => trim($row['last_name'].' '.$row['first_name'].' '.$row['middle_name']),
            'birth_date' => $row['birth_date'],
            // СНИЛС в предпросмотре показывается целиком по решению владельца:
            // оператор сверяет строки с исходным файлом, а по трём цифрам из
            // середины это невозможно. Паспорт и адрес остаются скрытыми.
            'snils' => $row['snils'],
            'email' => $this->maskEmail($row['email']),
            'address' => $row['address'] ? '[скрыто]' : '',
            'competition' => $row['competition_name'],
            'competition_matched' => $programMatched,
            'person' => $person ? 'найден Person #'.$person->id : 'новый Person',
            'application' => $application ? 'обновление #'.$application->id : 'новое заявление',
            'status' => $row['external_status'],
        ];
    }

    private function rowIssue(int $row, string $column, string $reason, ?string $value = null): array
    {
        return ['row' => $row, 'column' => $column, 'reason' => $reason, 'value' => $this->maskSensitive($column, $value)];
    }

    private function value(array $row, string $field): string
    {
        return $this->clean($this->raw($row, $field));
    }

    private function raw(array $row, string $field): mixed
    {
        return $row[self::HEADER_MAP[$field]] ?? '';
    }

    private function cellValue(mixed $raw, mixed $formatted): string
    {
        if (is_float($raw) || is_int($raw)) {
            return (string) $formatted;
        }
        return $this->clean($formatted);
    }

    private function clean(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    private function splitFio(string $fio): array
    {
        $parts = preg_split('/\s+/u', trim($fio)) ?: [];
        return [$parts[0] ?? '', $parts[1] ?? '', implode(' ', array_slice($parts, 2)) ?: null];
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            try { return SpreadsheetDate::excelToDateTimeObject((float) $value)->format('Y-m-d'); } catch (\Throwable) {}
        }
        $value = $this->clean($value);
        foreach (['d.m.Y', 'd.m.y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date) { return $date->toDateString(); }
            } catch (\Throwable) {}
        }
        try { return Carbon::parse($value)->toDateString(); } catch (\Throwable) { return null; }
    }

    private function decimal(string $value): ?float
    {
        $value = str_replace(',', '.', preg_replace('/[^0-9,\.\-]/u', '', $value) ?? '');
        return $value === '' ? null : (float) $value;
    }

    private function bool(string $value): ?bool
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') { return null; }
        return in_array($value, ['1', 'да', 'yes', 'true', '+', 'предоставлены', 'рекомендован'], true);
    }

    private function status(string $value): string
    {
        $value = mb_strtolower($value);
        return match (true) {
            str_contains($value, 'зачис') => 'enrolled',
            str_contains($value, 'отклон'), str_contains($value, 'отказ') => 'rejected',
            str_contains($value, 'прин') => 'accepted',
            default => 'new',
        };
    }

    private function normalizeGender(string $value): ?string
    {
        $value = mb_strtolower($value);
        return match (true) {
            str_starts_with($value, 'м') => 'male',
            str_starts_with($value, 'ж') => 'female',
            default => null,
        };
    }

    private function normalizedSnils(string $value): array
    {
        try {
            $snils = $this->snils->normalize($value);
            if ($snils === null) {
                return ['snils' => '', 'snils_raw' => $value, 'snils_error' => 'Укажите СНИЛС.'];
            }

            return ['snils' => $snils, 'snils_raw' => $value, 'snils_error' => null];
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return ['snils' => '', 'snils_raw' => $value, 'snils_error' => $exception->errors()['snils'][0] ?? 'СНИЛС указан некорректно.'];
        }
    }

    private function normalizeHeader(mixed $value): string
    {
        return mb_strtolower($this->clean($value));
    }

    private function normalizeComparable(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^а-яa-z0-9]+/u', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function newPersonKey(array $row): string
    {
        return sha1(implode('|', [$row['snils'], $row['last_name'], $row['first_name'], $row['middle_name'], $row['birth_date']]));
    }

    private function maskSensitive(string $column, ?string $value): ?string
    {
        if ($value === null) { return null; }
        $lower = mb_strtolower($column);
        // СНИЛС в строке об ошибке показывается ровно таким, как он записан в
        // файле: чинить предстоит именно это значение, а по маске не видно, что
        // с ним не так — лишняя цифра, буква или пустое место.
        if (str_contains($lower, 'снилс')) { return $value; }
        if (str_contains($lower, 'паспорт') || str_contains($lower, 'документ') || str_contains($lower, 'адрес')) { return '[скрыто]'; }
        return $value;
    }

    private function maskEmail(string $value): string
    {
        if (! str_contains($value, '@')) { return $value; }
        [$name, $domain] = explode('@', $value, 2);
        return mb_substr($name, 0, 1).'***@'.$domain;
    }
}
