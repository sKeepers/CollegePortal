<?php

namespace App\Services;

use App\Models\Teacher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeacherCsvService
{
    private const HEADERS = [
        'id',
        'last_name',
        'first_name',
        'middle_name',
        'phone',
        'email',
        'position',
        'department',
        'is_active',
    ];

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, self::HEADERS, ';');

            Teacher::query()
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->chunk(200, function ($teachers) use ($output): void {
                    foreach ($teachers as $teacher) {
                        fputcsv($output, [
                            $teacher->id,
                            $teacher->last_name,
                            $teacher->first_name,
                            $teacher->middle_name,
                            $teacher->phone,
                            $teacher->email,
                            $teacher->position,
                            $teacher->department,
                            $teacher->is_active ? '1' : '0',
                        ], ';');
                    }
                });

            fclose($output);
        }, 'teachers.csv', [
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

            if ($teacher) {
                $teacher->update($validated);
                $updated++;
            } else {
                Teacher::create($validated);
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

    private function rules(): array
    {
        return [
            'id' => ['nullable', 'integer', 'exists:teachers,id'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
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
