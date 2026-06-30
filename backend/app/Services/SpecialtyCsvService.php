<?php

namespace App\Services;

use App\Models\Specialty;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpecialtyCsvService
{
    private const HEADERS = [
        'id',
        'code',
        'name',
        'education_level',
        'qualification',
        'normative_study_years',
        'description',
    ];

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, self::HEADERS, ';');

            Specialty::query()
                ->orderBy('code')
                ->chunk(200, function ($specialties) use ($output): void {
                    foreach ($specialties as $specialty) {
                        fputcsv($output, [
                            $specialty->id,
                            $specialty->code,
                            $specialty->name,
                            $specialty->education_level,
                            $specialty->qualification,
                            $specialty->normative_study_years,
                            $specialty->description,
                        ], ';');
                    }
                });

            fclose($output);
        }, 'specialties.csv', [
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
            $specialty = $this->findSpecialty($payload);
            $validator = Validator::make($payload, $this->rules($specialty), $this->messages());

            if ($validator->fails()) {
                $errors[] = [
                    'line' => $line,
                    'messages' => $validator->errors()->all(),
                ];
                continue;
            }

            $validated = $validator->validated();
            unset($validated['id']);

            if ($specialty) {
                $specialty->update($validated);
                $updated++;
            } else {
                Specialty::create($validated);
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

        return array_map(fn ($value) => $value === '' ? null : $value, $payload);
    }

    private function findSpecialty(array $payload): ?Specialty
    {
        if (!empty($payload['id'])) {
            return Specialty::find($payload['id']);
        }

        return !empty($payload['code']) ? Specialty::where('code', $payload['code'])->first() : null;
    }

    private function rules(?Specialty $specialty): array
    {
        return [
            'id' => ['nullable', 'integer', 'exists:specialties,id'],
            'code' => ['required', 'string', 'max:50', Rule::unique('specialties', 'code')->ignore($specialty)],
            'name' => ['required', 'string', 'max:255'],
            'education_level' => ['required', 'string', 'max:255'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'normative_study_years' => ['nullable', 'numeric', 'min:0.5', 'max:10'],
            'description' => ['nullable', 'string'],
        ];
    }

    private function messages(): array
    {
        return [
            'id.exists' => 'Специальность с указанным id не найдена.',
            'code.required' => 'Не указан код специальности.',
            'code.unique' => 'Специальность с таким кодом уже существует.',
            'name.required' => 'Не указано название специальности.',
            'education_level.required' => 'Не указан уровень образования.',
            'normative_study_years.numeric' => 'Нормативный срок должен быть числом.',
        ];
    }
}
