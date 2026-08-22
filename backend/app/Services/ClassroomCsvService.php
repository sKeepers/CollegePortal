<?php

namespace App\Services;

use App\Models\Classroom;
use App\Support\Csv\CsvExport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClassroomCsvService
{
    private const HEADERS = [
        'id',
        'number',
        'building',
        'floor',
        'capacity',
        'type',
        'description',
    ];

    public function export(): StreamedResponse
    {
        return CsvExport::download('classrooms.csv', self::HEADERS, function (callable $row): void {
            Classroom::query()
                ->orderBy('building')
                ->orderBy('number')
                ->chunk(200, function ($classrooms) use ($row): void {
                    foreach ($classrooms as $classroom) {
                        $row([
                            $classroom->id,
                            $classroom->number,
                            $classroom->building,
                            $classroom->floor,
                            $classroom->capacity,
                            $classroom->type,
                            $classroom->description,
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
            $classroom = $this->findClassroom($payload);
            $validator = Validator::make($payload, $this->rules($classroom, $payload), $this->messages());

            if ($validator->fails()) {
                $errors[] = [
                    'line' => $line,
                    'messages' => $validator->errors()->all(),
                ];
                continue;
            }

            $validated = $validator->validated();
            unset($validated['id']);

            if ($classroom) {
                $classroom->update($validated);
                $updated++;
            } else {
                Classroom::create($validated);
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

        return array_map(fn ($value) => $value === '' ? null : $value, $payload);
    }

    private function findClassroom(array $payload): ?Classroom
    {
        if (!empty($payload['id'])) {
            return Classroom::find($payload['id']);
        }

        if (empty($payload['number'])) {
            return null;
        }

        return Classroom::query()
            ->where('number', $payload['number'])
            ->where('building', $payload['building'] ?? null)
            ->first();
    }

    private function rules(?Classroom $classroom, array $payload): array
    {
        return [
            'id' => ['nullable', 'integer', 'exists:classrooms,id'],
            'number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('classrooms', 'number')->where('building', $payload['building'] ?? null)->ignore($classroom),
            ],
            'building' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'integer', 'min:0', 'max:50'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'type' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }

    private function messages(): array
    {
        return [
            'id.exists' => 'Аудитория с указанным id не найдена.',
            'number.required' => 'Не указан номер аудитории.',
            'number.unique' => 'Аудитория с таким номером и корпусом уже существует.',
            'floor.integer' => 'Этаж должен быть числом.',
            'floor.min' => 'Этаж должен быть от 0 до 50.',
            'floor.max' => 'Этаж должен быть от 0 до 50.',
            'capacity.integer' => 'Вместимость должна быть числом.',
            'capacity.min' => 'Вместимость должна быть от 1 до 1000.',
            'capacity.max' => 'Вместимость должна быть от 1 до 1000.',
        ];
    }
}
