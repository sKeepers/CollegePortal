<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Student;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentCsvService
{
    private const HEADERS = [
        'id',
        'group_id',
        'group',
        'last_name',
        'first_name',
        'middle_name',
        'birth_date',
        'phone',
        'email',
        'snils',
        'address',
        'passport_series',
        'passport_number',
        'passport_issue_date',
        'passport_issued_by',
        'status',
        'course',
        'education_form',
        'enrollment_date',
        'enrollment_order_number',
        'enrollment_order_date',
    ];

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, self::HEADERS, ';');

            Student::query()
                ->with('group')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->chunk(200, function ($students) use ($output): void {
                    foreach ($students as $student) {
                        fputcsv($output, [
                            $student->id,
                            $student->group_id,
                            $student->group?->name,
                            $student->last_name,
                            $student->first_name,
                            $student->middle_name,
                            $student->birth_date?->toDateString(),
                            $student->phone,
                            $student->email,
                            $student->snils,
                            $student->address,
                            $student->passport_series,
                            $student->passport_number,
                            $student->passport_issue_date?->toDateString(),
                            $student->passport_issued_by,
                            $student->status,
                            $student->course,
                            $student->education_form,
                            $student->enrollment_date?->toDateString(),
                            $student->enrollment_order_number,
                            $student->enrollment_order_date?->toDateString(),
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
                $payload[$header] = $row[$index] ?? null;
            }
        }

        return $payload;
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
