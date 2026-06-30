<?php

namespace App\Services;

use App\Models\Group;
use App\Models\EducationProgram;
use App\Models\Teacher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GroupCsvService
{
    private const HEADERS = [
        'id',
        'name',
        'specialty',
        'education_program_id',
        'education_program',
        'course',
        'year_start',
        'curator_id',
        'curator',
    ];

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, self::HEADERS, ';');

            Group::query()
                ->with(['curator', 'educationProgram.specialty'])
                ->orderBy('name')
                ->chunk(200, function ($groups) use ($output): void {
                    foreach ($groups as $group) {
                        fputcsv($output, [
                            $group->id,
                            $group->name,
                            $group->specialty,
                            $group->education_program_id,
                            $group->educationProgram ? $this->educationProgramName($group->educationProgram) : null,
                            $group->course,
                            $group->year_start,
                            $group->curator_id,
                            $group->curator ? $this->teacherName($group->curator) : null,
                        ], ';');
                    }
                });

            fclose($output);
        }, 'groups.csv', [
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
            $group = $this->findGroup($payload);
            $validator = Validator::make($payload, $this->rules($group), $this->messages());

            if ($validator->fails()) {
                $errors[] = [
                    'line' => $line,
                    'messages' => $validator->errors()->all(),
                ];
                continue;
            }

            $validated = $validator->validated();
            unset($validated['id'], $validated['curator'], $validated['education_program']);

            if ($group) {
                $group->update($validated);
                $updated++;
            } else {
                Group::create($validated);
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

        if (empty($payload['curator_id']) && !empty($payload['curator'])) {
            $payload['curator_id'] = Teacher::query()
                ->get()
                ->first(fn (Teacher $teacher) => $this->teacherName($teacher) === $payload['curator'])
                ?->id ?? '__missing__';
        }

        if (empty($payload['education_program_id']) && !empty($payload['education_program'])) {
            $payload['education_program_id'] = EducationProgram::query()
                ->with('specialty')
                ->get()
                ->first(fn (EducationProgram $program) => $this->educationProgramName($program) === $payload['education_program'])
                ?->id ?? '__missing__';
        }

        return $payload;
    }

    private function findGroup(array $payload): ?Group
    {
        if (!empty($payload['id'])) {
            return Group::find($payload['id']);
        }

        if (!empty($payload['name'])) {
            return Group::where('name', $payload['name'])->first();
        }

        return null;
    }

    private function rules(?Group $group): array
    {
        return [
            'id' => ['nullable', 'integer', 'exists:groups,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('groups', 'name')->ignore($group),
            ],
            'specialty' => ['required', 'string', 'max:255'],
            'education_program_id' => ['nullable', 'integer', 'exists:education_programs,id'],
            'education_program' => ['nullable', 'string', 'max:255'],
            'course' => ['required', 'integer', 'min:1', 'max:6'],
            'year_start' => ['required', 'integer', 'min:2000', 'max:2100'],
            'curator_id' => ['nullable', 'integer', 'exists:teachers,id'],
            'curator' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function messages(): array
    {
        return [
            'id.exists' => 'Группа с указанным id не найдена.',
            'name.required' => 'Не указано название группы.',
            'name.unique' => 'Группа с таким названием уже существует.',
            'specialty.required' => 'Не указана специальность.',
            'education_program_id.integer' => 'Образовательная программа не найдена.',
            'education_program_id.exists' => 'Образовательная программа не найдена.',
            'course.required' => 'Не указан курс.',
            'course.integer' => 'Курс должен быть числом от 1 до 6.',
            'course.min' => 'Курс должен быть числом от 1 до 6.',
            'course.max' => 'Курс должен быть числом от 1 до 6.',
            'year_start.required' => 'Не указан год набора.',
            'year_start.integer' => 'Год набора должен быть числом.',
            'year_start.min' => 'Год набора должен быть не раньше 2000.',
            'year_start.max' => 'Год набора должен быть не позже 2100.',
            'curator_id.integer' => 'Куратор не найден.',
            'curator_id.exists' => 'Куратор не найден.',
        ];
    }

    private function teacherName(Teacher $teacher): string
    {
        return collect([$teacher->last_name, $teacher->first_name, $teacher->middle_name])
            ->filter()
            ->join(' ');
    }

    private function educationProgramName(EducationProgram $program): string
    {
        return collect([
            $program->name,
            $program->specialty?->code,
            $program->year_start,
            $program->study_form,
        ])->filter()->join(' · ');
    }
}
