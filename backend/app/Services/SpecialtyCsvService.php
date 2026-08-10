<?php

namespace App\Services;

use App\Models\Specialty;
use App\Services\Import\SpecialtyImportHandler;
use App\Support\Csv\CsvExport;
use App\Support\Csv\CsvImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpecialtyCsvService
{
    /** Колонки, которые принимает собственный CSV-импорт специальностей. */
    private const HEADERS = [
        'id',
        'code',
        'name',
        'education_level',
        'qualification',
        'normative_study_years',
        'description',
    ];

    /**
     * Заголовки шаблона импорта в технические имена этого сервиса. Прежние
     * машинные заголовки продолжают работать: файлы по старому образцу
     * никуда не делись.
     */
    private const LABEL_TO_COLUMN = [
        'код' => 'code',
        'код специальности' => 'code',
        'название' => 'name',
        'специальность' => 'name',
        'уровень образования' => 'education_level',
        'уровень' => 'education_level',
        'квалификация' => 'qualification',
        'нормативный срок' => 'normative_study_years',
        'срок обучения' => 'normative_study_years',
        'описание' => 'description',
    ];

    /**
     * Выгрузка идёт колонками шаблона импорта, а не машинными именами полей.
     * Идентификатор убран: специальность находится по коду — он же ключ
     * импорта, — а порядковый номер строки владельцу в Excel не нужен.
     */
    public function export(): StreamedResponse
    {
        $handler = app(SpecialtyImportHandler::class);

        return CsvExport::download('specialties.csv', $handler->templateHeaders(), function (callable $row): void {
            Specialty::query()
                ->orderBy('code')
                ->chunk(200, function ($specialties) use ($row): void {
                    foreach ($specialties as $specialty) {
                        // Порядок обязан совпадать с templateHeaders() обработчика импорта.
                        $row([
                            $specialty->code,
                            $specialty->name,
                            $specialty->education_level,
                            $specialty->qualification,
                            $specialty->normative_study_years,
                            $specialty->description,
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
