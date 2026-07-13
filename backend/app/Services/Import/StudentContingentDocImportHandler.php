<?php

namespace App\Services\Import;

use App\Models\Group;
use App\Models\ImportJob;
use App\Models\Person;
use App\Models\Specialty;
use App\Models\Student;
use App\Models\StudentStatusHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class StudentContingentDocImportHandler
{
    public const SOURCE = 'student_contingent_doc';

    private const REVIEW_HEADERS = [
        'source_page', 'source_section', 'specialty_code', 'specialty_name', 'profile_name', 'course', 'funding_type',
        'group_name_raw', 'source_row_number', 'internal_number', 'last_name', 'first_name', 'middle_name', 'full_name_raw',
        'birth_date', 'enrollment_order_number', 'enrollment_order_date', 'address_raw', 'phone_raw', 'student_status',
        'academic_leave_order_number', 'academic_leave_order_date', 'recovery_order_number', 'recovery_order_date',
        'transfer_notes', 'dismissal_notes', 'notes_raw', 'parse_status', 'parse_warnings',
    ];

    public function analyzePath(string $path, ?ImportJob $job = null): array
    {
        $text = $this->extractText($path);
        $rows = $this->parseRows($text);
        $summary = $this->evaluateRows($rows, false, $job);
        $artifacts = $job ? $this->writeArtifacts($job, $rows, $summary) : [];

        return array_merge($summary, [
            'headers' => self::REVIEW_HEADERS,
            'file_hash' => hash_file('sha256', $path),
            'artifacts' => $artifacts,
        ]);
    }

    public function dryRunJob(ImportJob $job): array
    {
        $summary = $this->analyzePath(Storage::disk('local')->path($job->stored_path), $job);
        $summary['mode'] = 'dry_run';

        return $summary;
    }

    public function applyJob(ImportJob $job): array
    {
        $text = $this->extractText(Storage::disk('local')->path($job->stored_path));
        $rows = $this->parseRows($text);
        $dryRun = $this->evaluateRows($rows, false, $job);

        if (($dryRun['error_rows'] ?? 0) > 0 || ($dryRun['review_required'] ?? 0) > 0 || ($dryRun['blockers'] ?? 0) > 0) {
            throw new RuntimeException('Apply заблокирован: dry-run содержит ошибки, неоднозначные Person или записи review_required.');
        }

        return DB::transaction(function () use ($rows, $job): array {
            $summary = $this->evaluateRows($rows, true, $job);
            $summary['mode'] = 'apply';
            $summary['artifacts'] = $this->writeArtifacts($job, $rows, $summary);

            return $summary;
        });
    }

    private function extractText(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException('Файл контингента студентов не найден.');
        }

        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            throw new RuntimeException('Файл контингента студентов пуст или недоступен для чтения.');
        }

        if ($this->looksLikePlainText($contents)) {
            return $this->normalizeText($contents);
        }

        $utf16 = @mb_convert_encoding($contents, 'UTF-8', 'UTF-16LE');
        if (is_string($utf16) && $this->looksLikeExtractedText($utf16)) {
            return $this->normalizeText($utf16);
        }

        $cp1251 = @mb_convert_encoding($contents, 'UTF-8', 'Windows-1251');
        if (is_string($cp1251) && $this->looksLikeExtractedText($cp1251)) {
            return $this->normalizeText($cp1251);
        }

        throw new RuntimeException('Не удалось извлечь текст из DOC. Нужен текстовый DOC/экспорт или установленный конвертер для legacy DOC.');
    }

    private function parseRows(string $text): array
    {
        $rows = [];
        $context = [
            'source_page' => null,
            'source_section' => null,
            'specialty_code' => null,
            'specialty_name' => null,
            'profile_name' => null,
            'course' => null,
            'funding_type' => null,
            'group_name_raw' => null,
        ];

        $lineNumber = 0;
        foreach (preg_split('/\R/u', $text) as $line) {
            $lineNumber++;
            $line = trim(preg_replace('/\s+/u', ' ', str_replace(["\t", "\xc2\xa0"], ' ', $line)));
            if ($line === '') {
                continue;
            }

            $this->updateContext($context, $line);
            $row = $this->parseStudentLine($line, $lineNumber, $context);
            if ($row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function updateContext(array &$context, string $line): void
    {
        if (preg_match('/(?:стр(?:аница)?|page)\s*[:№#-]?\s*(\d+)/iu', $line, $m)) {
            $context['source_page'] = (int) $m[1];
        }
        if (preg_match('/(?:специальность|код специальности)\s*[:\-]?\s*((?:\d{2}\.){2}\d{2})\s*(.*)$/iu', $line, $m)) {
            $context['specialty_code'] = trim($m[1]);
            $context['specialty_name'] = trim($m[2]);
            $context['source_section'] = trim($line);
        }
        if (preg_match('/(?:специализация|профиль)\s*[:\-]?\s*(.+)$/iu', $line, $m)) {
            $context['profile_name'] = trim($m[1]);
        }
        if (preg_match('/\b([1-4])\s*(?:курс|к\.)\b/iu', $line, $m)) {
            $context['course'] = (int) $m[1];
        }
        if (preg_match('/\b(бюджет|внебюджет|договор|платн\w*)\b/iu', $line, $m)) {
            $context['funding_type'] = $this->normalizeFunding($m[1]);
        }
        if (preg_match('/(?:группа|гр\.)\s*[:№#-]?\s*([А-ЯA-Z0-9\-\/ ]{2,30})/iu', $line, $m)) {
            $context['group_name_raw'] = $this->normalizeGroupName($m[1]);
        }
    }

    private function parseStudentLine(string $line, int $lineNumber, array $context): ?array
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s*[;|]\s*/u', $line)), fn ($part) => $part !== ''));
        if (count($parts) >= 4 && preg_match('/^\d+$/', $parts[0]) && preg_match('/\p{Lu}\p{Ll}+/u', $parts[2] ?? '')) {
            return $this->rowFromParts($parts, $lineNumber, $context);
        }

        if (! preg_match('/^\s*(\d{1,4})[\.)]?\s+(?:(\d{1,6})\s+)?([А-ЯЁ][а-яё\-]+\s+[А-ЯЁ][а-яё\-]+(?:\s+[А-ЯЁ][а-яё\-]+)?)\s+(\d{2}[\.\/-]\d{2}[\.\/-]\d{4})(.*)$/u', $line, $m)) {
            return null;
        }

        $names = $this->splitName($m[3]);
        $tail = trim($m[5]);
        [$orderNumber, $orderDate] = $this->extractOrder($tail);

        return $this->baseRow($context, $lineNumber, [
            'internal_number' => $m[2] ?: $m[1],
            'last_name' => $names[0],
            'first_name' => $names[1],
            'middle_name' => $names[2],
            'full_name_raw' => $m[3],
            'birth_date' => $this->parseDateValue($m[4]),
            'enrollment_order_number' => $orderNumber,
            'enrollment_order_date' => $orderDate,
            'address_raw' => $this->extractAddress($tail),
            'phone_raw' => $this->extractPhone($tail),
            'student_status' => $this->detectStatus($tail),
            'notes_raw' => $tail,
        ]);
    }

    private function rowFromParts(array $parts, int $lineNumber, array $context): array
    {
        $names = $this->splitName($parts[2] ?? '');
        [$orderNumber, $orderDate] = $this->extractOrder($parts[4] ?? '');
        $notes = implode('; ', array_slice($parts, 7));
        $statusSource = implode(' ', array_slice($parts, 7));

        return $this->baseRow($context, $lineNumber, [
            'internal_number' => $parts[1] ?? $parts[0],
            'last_name' => $names[0],
            'first_name' => $names[1],
            'middle_name' => $names[2],
            'full_name_raw' => $parts[2] ?? '',
            'birth_date' => $this->parseDateValue($parts[3] ?? ''),
            'enrollment_order_number' => $orderNumber,
            'enrollment_order_date' => $orderDate,
            'address_raw' => $parts[5] ?? '',
            'phone_raw' => $parts[6] ?? '',
            'student_status' => $this->detectStatus($statusSource),
            'academic_leave_order_number' => str_contains(mb_strtolower($statusSource), 'академ') ? $orderNumber : null,
            'academic_leave_order_date' => str_contains(mb_strtolower($statusSource), 'академ') ? $orderDate : null,
            'recovery_order_number' => str_contains(mb_strtolower($statusSource), 'восстанов') ? $orderNumber : null,
            'recovery_order_date' => str_contains(mb_strtolower($statusSource), 'восстанов') ? $orderDate : null,
            'transfer_notes' => str_contains(mb_strtolower($statusSource), 'перев') ? $statusSource : null,
            'dismissal_notes' => str_contains(mb_strtolower($statusSource), 'отчисл') ? $statusSource : null,
            'notes_raw' => $notes,
        ]);
    }

    private function baseRow(array $context, int $lineNumber, array $data): array
    {
        $row = array_merge([
            'source_page' => $context['source_page'],
            'source_section' => $context['source_section'],
            'specialty_code' => $context['specialty_code'],
            'specialty_name' => $context['specialty_name'],
            'profile_name' => $context['profile_name'],
            'course' => $context['course'],
            'funding_type' => $context['funding_type'],
            'group_name_raw' => $context['group_name_raw'],
            'source_row_number' => $lineNumber,
            'internal_number' => null,
            'last_name' => null,
            'first_name' => null,
            'middle_name' => null,
            'full_name_raw' => null,
            'birth_date' => null,
            'enrollment_order_number' => null,
            'enrollment_order_date' => null,
            'address_raw' => null,
            'phone_raw' => null,
            'student_status' => 'active',
            'academic_leave_order_number' => null,
            'academic_leave_order_date' => null,
            'recovery_order_number' => null,
            'recovery_order_date' => null,
            'transfer_notes' => null,
            'dismissal_notes' => null,
            'notes_raw' => null,
            'parse_status' => 'parsed',
            'parse_warnings' => [],
        ], $data);

        foreach (['specialty_code' => 'Не определена специальность', 'group_name_raw' => 'Не определена группа', 'birth_date' => 'Некорректная дата рождения'] as $field => $warning) {
            if (empty($row[$field])) {
                $row['parse_warnings'][] = $warning;
            }
        }
        if (empty($row['last_name']) || empty($row['first_name'])) {
            $row['parse_warnings'][] = 'Не удалось надежно разобрать ФИО';
        }
        $row['parse_status'] = $row['parse_warnings'] ? 'review_required' : 'parsed';
        $row['parse_warnings'] = implode('; ', array_unique($row['parse_warnings']));

        return $row;
    }

    private function evaluateRows(array $rows, bool $apply, ?ImportJob $job): array
    {
        $summary = $this->emptySummary($apply);
        $preview = [];

        foreach ($rows as $row) {
            $evaluation = $this->evaluateRow($row);
            $summary['total_rows']++;
            $summary['sections'][$row['source_section'] ?: 'Без раздела'] = true;
            if ($row['specialty_code'] && ! $evaluation['specialty']) {
                $summary['unknown_specialties'][$row['specialty_code'].' '.$row['specialty_name']] = true;
            }
            if ($row['group_name_raw'] && ! $evaluation['group']) {
                $summary['unknown_groups'][$row['group_name_raw']] = true;
            }
            if ($evaluation['status'] === 'valid') {
                $summary['valid_rows']++;
            } elseif ($evaluation['status'] === 'review_required') {
                $summary['review_required']++;
            } else {
                $summary['error_rows']++;
            }
            $summary['blockers'] += count($evaluation['blockers']);
            $summary['warnings'] = array_merge($summary['warnings'], $evaluation['warnings']);
            $summary['errors'] = array_merge($summary['errors'], $evaluation['errors']);
            $preview[] = $this->previewRow($row, $evaluation);

            if ($apply && $evaluation['status'] === 'valid') {
                $this->applyRow($row, $evaluation, $job, $summary);
            }
        }

        $summary['section_types'] = array_keys($summary['sections']);
        $summary['unknown_specialties_count'] = count($summary['unknown_specialties']);
        $summary['unknown_groups_count'] = count($summary['unknown_groups']);
        $summary['unknown_specialties'] = array_keys($summary['unknown_specialties']);
        $summary['unknown_groups'] = array_keys($summary['unknown_groups']);
        $summary['preview_rows'] = array_slice($preview, 0, 25);

        return $summary;
    }

    private function evaluateRow(array $row): array
    {
        $specialty = $row['specialty_code'] ? Specialty::query()->where('code', $row['specialty_code'])->first() : null;
        $group = $row['group_name_raw'] ? Group::query()->where('name', $row['group_name_raw'])->first() : null;
        $people = $this->findPersonCandidates($row);
        $student = $this->findStudentConflict($row, $group);
        $warnings = [];
        $errors = [];
        $blockers = [];

        if ($row['parse_status'] === 'review_required') {
            $warnings[] = $this->issue($row, null, 'Строка требует ручной проверки: '.$row['parse_warnings']);
        }
        if (! $specialty) {
            $blockers[] = 'unknown_specialty';
            $errors[] = $this->issue($row, 'specialty_code', 'Не найдена специальность.', $row['specialty_code']);
        }
        if (! $group) {
            $blockers[] = 'unknown_group';
            $errors[] = $this->issue($row, 'group_name_raw', 'Не найдена группа.', $row['group_name_raw']);
        }
        if (! $row['birth_date']) {
            $blockers[] = 'invalid_birth_date';
            $errors[] = $this->issue($row, 'birth_date', 'Некорректная дата рождения.', null);
        }
        if ($people->count() > 1) {
            $blockers[] = 'ambiguous_person';
            $errors[] = $this->issue($row, 'full_name_raw', 'Найдено несколько возможных Person.', '[скрыто]');
        }
        if ($student && $student->group_id !== $group?->id) {
            $blockers[] = 'active_student_conflict';
            $errors[] = $this->issue($row, 'full_name_raw', 'Найден активный студент с другим профилем/группой.', '[скрыто]');
        }

        return [
            'status' => $blockers ? 'error' : ($row['parse_status'] === 'review_required' ? 'review_required' : 'valid'),
            'specialty' => $specialty,
            'group' => $group,
            'person' => $people->count() === 1 ? $people->first() : null,
            'student' => $student,
            'warnings' => $warnings,
            'errors' => $errors,
            'blockers' => $blockers,
        ];
    }

    private function applyRow(array $row, array $evaluation, ?ImportJob $job, array &$summary): void
    {
        $person = $evaluation['person'] ?: Person::create([
            'last_name' => $row['last_name'],
            'first_name' => $row['first_name'],
            'middle_name' => $row['middle_name'],
            'birth_date' => $row['birth_date'],
            'phone' => $this->cleanPhone($row['phone_raw']),
            'address' => $row['address_raw'],
            'status' => 'active',
        ]);

        $student = $evaluation['student'] ?: Student::query()
            ->where('person_id', $person->id)
            ->where('group_id', $evaluation['group']->id)
            ->first();

        $payload = [
            'person_id' => $person->id,
            'group_id' => $evaluation['group']->id,
            'course' => $row['course'] ?: $evaluation['group']->course,
            'last_name' => $row['last_name'],
            'first_name' => $row['first_name'],
            'middle_name' => $row['middle_name'],
            'birth_date' => $row['birth_date'],
            'phone' => $this->cleanPhone($row['phone_raw']),
            'status' => $row['student_status'] ?: 'active',
            'enrollment_date' => $row['enrollment_order_date'],
            'funding_form' => $row['funding_type'],
        ];

        if ($student) {
            $student->fill($payload)->save();
            $summary['updated_count']++;
        } else {
            $student = Student::create($payload);
            $summary['created_count']++;
        }

        $person->fill(array_filter([
            'phone' => $this->cleanPhone($row['phone_raw']),
            'address' => $row['address_raw'],
        ], fn ($value) => $value !== null && $value !== ''))->save();

        foreach ($this->statusHistoryRows($row) as $history) {
            StudentStatusHistory::query()->updateOrCreate([
                'student_id' => $student->id,
                'status' => $history['status'],
                'source' => self::SOURCE,
                'order_number' => $history['order_number'],
                'order_date' => $history['order_date'],
            ], array_merge($history, [
                'student_id' => $student->id,
                'source' => self::SOURCE,
                'import_job_id' => $job?->id,
            ]));
        }
    }

    private function emptySummary(bool $apply): array
    {
        return [
            'mode' => $apply ? 'apply' : 'dry_run',
            'source' => self::SOURCE,
            'total_rows' => 0,
            'valid_rows' => 0,
            'review_required' => 0,
            'error_rows' => 0,
            'blockers' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'warnings' => [],
            'errors' => [],
            'sections' => [],
            'section_types' => [],
            'unknown_specialties' => [],
            'unknown_groups' => [],
            'unknown_specialties_count' => 0,
            'unknown_groups_count' => 0,
            'preview_rows' => [],
        ];
    }

    private function writeArtifacts(ImportJob $job, array $rows, array $summary): array
    {
        $directory = 'imports/students/'.$job->id;
        Storage::disk('local')->makeDirectory($directory);
        $csvPath = $directory.'/student-contingent-normalized.csv';
        $jsonPath = $directory.'/student-contingent-report.json';
        $xlsxPath = $directory.'/student-contingent-review.xlsx';

        Storage::disk('local')->put($csvPath, $this->csv($rows));
        Storage::disk('local')->put($jsonPath, json_encode($this->safeReport($summary), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->writeReviewXlsx(Storage::disk('local')->path($xlsxPath), $rows);

        return ['normalized_csv' => $csvPath, 'review_xlsx' => $xlsxPath, 'report_json' => $jsonPath];
    }

    private function writeReviewXlsx(string $path, array $rows): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Проверка');
        $sheet->fromArray(self::REVIEW_HEADERS, null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray(array_map(fn ($field) => $row[$field] ?? null, self::REVIEW_HEADERS), null, 'A'.($index + 2));
        }
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::REVIEW_HEADERS);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($field) => $row[$field] ?? null, self::REVIEW_HEADERS));
        }
        rewind($handle);
        return stream_get_contents($handle) ?: '';
    }

    private function safeReport(array $summary): array
    {
        return collect($summary)->only([
            'mode', 'source', 'total_rows', 'valid_rows', 'review_required', 'error_rows', 'blockers',
            'created_count', 'updated_count', 'skipped_count', 'section_types', 'unknown_specialties_count', 'unknown_groups_count',
        ])->all();
    }

    private function previewRow(array $row, array $evaluation): array
    {
        return [
            'source_row_number' => $row['source_row_number'],
            'group' => $row['group_name_raw'],
            'student' => $this->maskName($row['full_name_raw']),
            'birth_year' => $row['birth_date'] ? Carbon::parse($row['birth_date'])->format('Y') : null,
            'status' => $evaluation['status'],
            'specialty' => $evaluation['specialty'] ? 'matched' : 'unknown',
            'person' => $evaluation['person'] ? 'existing' : 'new_or_unknown',
            'blockers' => implode(', ', $evaluation['blockers']),
        ];
    }

    private function findPersonCandidates(array $row)
    {
        return Person::query()
            ->where('last_name', $row['last_name'])
            ->where('first_name', $row['first_name'])
            ->when($row['middle_name'], fn ($query) => $query->where('middle_name', $row['middle_name']))
            ->when($row['birth_date'], fn ($query) => $query->whereDate('birth_date', $row['birth_date']))
            ->limit(3)
            ->get();
    }

    private function findStudentConflict(array $row, ?Group $group): ?Student
    {
        return Student::query()
            ->where('last_name', $row['last_name'])
            ->where('first_name', $row['first_name'])
            ->when($row['middle_name'], fn ($query) => $query->where('middle_name', $row['middle_name']))
            ->when($row['birth_date'], fn ($query) => $query->whereDate('birth_date', $row['birth_date']))
            ->where('status', 'active')
            ->first();
    }

    private function statusHistoryRows(array $row): array
    {
        $items = [[
            'status' => $row['student_status'] ?: 'active',
            'order_number' => $row['enrollment_order_number'],
            'order_date' => $row['enrollment_order_date'],
            'notes' => $row['notes_raw'],
        ]];
        if ($row['academic_leave_order_number'] || $row['academic_leave_order_date']) {
            $items[] = ['status' => 'academic_leave', 'order_number' => $row['academic_leave_order_number'], 'order_date' => $row['academic_leave_order_date'], 'notes' => $row['notes_raw']];
        }
        if ($row['recovery_order_number'] || $row['recovery_order_date']) {
            $items[] = ['status' => 'recovered', 'order_number' => $row['recovery_order_number'], 'order_date' => $row['recovery_order_date'], 'notes' => $row['notes_raw']];
        }
        return $items;
    }

    private function issue(array $row, ?string $column, string $reason, mixed $value = null): array
    {
        return ['row' => $row['source_row_number'], 'column' => $column, 'reason' => $reason, 'value' => $value];
    }

    private function parseDateValue(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach (['d.m.Y', 'd/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->format('Y-m-d');
                }
            } catch (\Throwable) {
            }
        }
        return null;
    }

    private function extractOrder(string $value): array
    {
        $number = null;
        $date = null;
        if (preg_match('/(?:приказ|№|N)\s*([\w\-\/]+).*?(\d{2}[\.\/-]\d{2}[\.\/-]\d{4})/iu', $value, $m)) {
            $number = $m[1];
            $date = $this->parseDateValue($m[2]);
        }
        return [$number, $date];
    }

    private function extractPhone(string $value): ?string
    {
        return preg_match('/(?:\+?7|8)[\d\s\-\(\)]{9,}/u', $value, $m) ? trim($m[0]) : null;
    }

    private function extractAddress(string $value): ?string
    {
        return preg_match('/(?:адрес|ул\.|улица|пр\.|пер\.|г\.)\s*[:\-]?\s*(.+?)(?:\+?7|8\d|$)/iu', $value, $m) ? trim($m[1]) : null;
    }

    private function detectStatus(string $value): string
    {
        $lower = mb_strtolower($value);
        return match (true) {
            str_contains($lower, 'отчисл') => 'dismissed',
            str_contains($lower, 'академ') => 'academic_leave',
            str_contains($lower, 'восстанов') => 'active',
            str_contains($lower, 'перев') => 'transferred',
            default => 'active',
        };
    }


    private function normalizeGroupName(string $value): string
    {
        $value = preg_replace('/\b(бюджет|внебюджет|договор|платн\w*)\b/iu', '', $value) ?: $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?: $value);
    }

    private function normalizeFunding(string $value): string
    {
        $lower = mb_strtolower($value);
        return str_contains($lower, 'бюдж') ? 'budget' : 'contract';
    }

    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
        return [$parts[0] ?? null, $parts[1] ?? null, $parts[2] ?? null];
    }

    private function cleanPhone(?string $value): ?string
    {
        $value = preg_replace('/[^0-9+]/', '', (string) $value);
        return $value ?: null;
    }

    private function maskName(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name)) ?: [];
        return collect($parts)->filter()->map(fn ($part) => mb_substr($part, 0, 1).'***')->implode(' ');
    }

    private function looksLikePlainText(string $contents): bool
    {
        return ! str_contains(substr($contents, 0, 512), "\0") && $this->looksLikeExtractedText($contents);
    }

    private function looksLikeExtractedText(string $text): bool
    {
        return preg_match('/(специальность|группа|курс|студент|контингент)/iu', $text) === 1;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace("\0", '', $text);
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', ' ', $text) ?: $text;
    }
}
