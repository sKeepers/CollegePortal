<?php

namespace App\Services;

use App\Models\EducationProgram;
use App\Models\Specialty;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EducationProgramCsvService
{
    private const HEADERS = [
        'id',
        'specialty_id',
        'specialty_code',
        'name',
        'year_start',
        'study_form',
        'study_years',
        'is_active',
        'description',
    ];

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, self::HEADERS, ';');

            EducationProgram::query()
                ->with('specialty')
                ->orderByDesc('year_start')
                ->orderBy('name')
                ->chunk(200, function ($programs) use ($output): void {
                    foreach ($programs as $program) {
                        fputcsv($output, [
                            $program->id,
                            $program->specialty_id,
                            $program->specialty?->code,
                            $program->name,
                            $program->year_start,
                            $program->study_form,
                            $program->study_years,
                            $program->is_active ? '1' : '0',
                            $program->description,
                        ], ';');
                    }
                });

            fclose($output);
        }, 'education-programs.csv', [
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
            $program = $this->findProgram($payload);
            $validator = Validator::make($payload, $this->rules($program, $payload), $this->messages());

            if ($validator->fails()) {
                $errors[] = [
                    'line' => $line,
                    'messages' => $validator->errors()->all(),
                ];
                continue;
            }

            $validated = $validator->validated();
            unset($validated['id'], $validated['specialty_code']);

            if ($program) {
                $program->update($validated);
                $updated++;
            } else {
                EducationProgram::create($validated);
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
        return array_map(fn (string $header) => trim($header, "\xEF\xBB\xBF \t\n\r\0\x0B"), $headers);
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

        if (empty($payload['specialty_id']) && !empty($payload['specialty_code'])) {
            $payload['specialty_id'] = Specialty::where('code', $payload['specialty_code'])->value('id') ?? '__missing__';
        }

        $payload['is_active'] = $this->normalizeBoolean($payload['is_active'] ?? null);

        return $payload;
    }

    private function normalizeBoolean(?string $value): bool|string
    {
        if ($value === null) {
            return true;
        }

        return match (mb_strtolower($value)) {
            '1', 'true', 'yes', 'y', 'да', 'активна', 'действует' => true,
            '0', 'false', 'no', 'n', 'нет', 'неактивна', 'закрыта' => false,
            default => $value,
        };
    }

    private function findProgram(array $payload): ?EducationProgram
    {
        if (!empty($payload['id'])) {
            return EducationProgram::find($payload['id']);
        }

        if (empty($payload['specialty_id']) || empty($payload['name']) || empty($payload['year_start']) || empty($payload['study_form'])) {
            return null;
        }

        return EducationProgram::query()
            ->where('specialty_id', $payload['specialty_id'])
            ->where('name', $payload['name'])
            ->where('year_start', $payload['year_start'])
            ->where('study_form', $payload['study_form'])
            ->first();
    }

    private function rules(?EducationProgram $program, array $payload): array
    {
        return [
            'id' => ['nullable', 'integer', 'exists:education_programs,id'],
            'specialty_id' => ['required', 'integer', 'exists:specialties,id'],
            'specialty_code' => ['nullable', 'string', 'max:50'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('education_programs', 'name')
                    ->where('specialty_id', $payload['specialty_id'] ?? null)
                    ->where('year_start', $payload['year_start'] ?? null)
                    ->where('study_form', $payload['study_form'] ?? null)
                    ->ignore($program),
            ],
            'year_start' => ['required', 'integer', 'min:2000', 'max:2100'],
            'study_form' => ['required', 'string', 'max:100'],
            'study_years' => ['nullable', 'numeric', 'min:0.5', 'max:10'],
            'is_active' => ['required', 'boolean'],
            'description' => ['nullable', 'string'],
        ];
    }

    private function messages(): array
    {
        return [
            'id.exists' => 'Образовательная программа с указанным id не найдена.',
            'specialty_id.required' => 'Не указана специальность. Заполните specialty_id или specialty_code.',
            'specialty_id.integer' => 'Специальность не найдена.',
            'specialty_id.exists' => 'Специальность не найдена.',
            'name.required' => 'Не указано название программы.',
            'name.unique' => 'Такая образовательная программа уже существует.',
            'year_start.required' => 'Не указан год начала.',
            'study_form.required' => 'Не указана форма обучения.',
            'is_active.boolean' => 'Статус должен быть 1/0, true/false или да/нет.',
        ];
    }
}
