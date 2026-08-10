<?php

namespace App\Services;

use App\Models\EducationProgram;
use App\Models\Specialty;
use App\Services\Import\EducationProgramImportHandler;
use App\Support\Csv\CsvExport;
use App\Support\Csv\CsvImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EducationProgramCsvService
{
    /** Колонки, которые принимает собственный CSV-импорт программ. */
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

    /**
     * Заголовки шаблона импорта в технические имена этого сервиса. Прежние
     * машинные заголовки продолжают работать: файлы по старому образцу
     * никуда не делись.
     */
    private const LABEL_TO_COLUMN = [
        'код специальности' => 'specialty_code',
        'специальность' => 'specialty_code',
        'программа' => 'name',
        'образовательная программа' => 'name',
        'название' => 'name',
        'год начала' => 'year_start',
        'год набора' => 'year_start',
        'форма обучения' => 'study_form',
        'срок обучения' => 'study_years',
        'активна' => 'is_active',
        'активен' => 'is_active',
        'описание' => 'description',
    ];

    /**
     * Выгрузка идёт колонками шаблона импорта. Специальность выгружается
     * кодом: идентификатор строки владелец в Excel не наберёт, а код есть в
     * документах. Признак «Активна» выгружается словом, а не единицей и нулём —
     * его же понимает обратная загрузка.
     */
    public function export(): StreamedResponse
    {
        $handler = app(EducationProgramImportHandler::class);

        return CsvExport::download('education-programs.csv', $handler->templateHeaders(), function (callable $row): void {
            EducationProgram::query()
                ->with('specialty')
                ->orderByDesc('year_start')
                ->orderBy('name')
                ->chunk(200, function ($programs) use ($row): void {
                    foreach ($programs as $program) {
                        // Порядок обязан совпадать с templateHeaders() обработчика импорта.
                        $row([
                            $program->specialty?->code,
                            $program->name,
                            $program->year_start,
                            $program->study_form,
                            $program->study_years,
                            $program->is_active ? 'да' : 'нет',
                            $program->description,
                        ]);
                    }
                });
        });
    }

    public function import(UploadedFile $file): array
    {
        if (! CsvImport::hasHeader($file->getRealPath())) {
            throw new RuntimeException('CSV-файл пустой.');
        }

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach (CsvImport::rows($file->getRealPath()) as $line => $row) {
            $payload = $this->normalizePayload($this->canonicalize($row));
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

        return [
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    /** @param array<string, string> $row */
    private function canonicalize(array $row): array
    {
        $payload = [];

        foreach ($row as $header => $value) {
            if (trim($header) !== '') {
                $payload[$this->canonicalColumn($header)] = $value;
            }
        }

        return $payload;
    }

    /** Русская подпись шаблона или машинное имя — оба приводятся к одному ключу. */
    private function canonicalColumn(string $header): string
    {
        return self::LABEL_TO_COLUMN[mb_strtolower(trim($header))] ?? $header;
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
