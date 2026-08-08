<?php

namespace App\Services;

use App\Models\Subject;
use App\Models\Teacher;
use App\Support\Csv\CsvExport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubjectCsvService
{
    private const HEADERS = [
        'id',
        'name',
        'code',
        'department',
        'description',
        'teacher_ids',
        'teachers',
    ];

    public function export(): StreamedResponse
    {
        return CsvExport::download('subjects.csv', self::HEADERS, function (callable $row): void {
            Subject::query()
                ->with('teachers')
                ->orderBy('name')
                ->chunk(200, function ($subjects) use ($row): void {
                    foreach ($subjects as $subject) {
                        $row([
                            $subject->id,
                            $subject->name,
                            $subject->code,
                            $subject->department,
                            $subject->description,
                            $subject->teachers->pluck('id')->join(','),
                            $subject->teachers->map(fn (Teacher $teacher) => $this->teacherName($teacher))->join(' | '),
                        ]);
                    }
                });
        });
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
            $subject = $this->findSubject($payload);
            $validator = Validator::make($payload, $this->rules($subject), $this->messages());

            if ($validator->fails()) {
                $errors[] = [
                    'line' => $line,
                    'messages' => $validator->errors()->all(),
                ];
                continue;
            }

            $validated = $validator->validated();
            $teacherIds = $validated['teacher_ids'] ?? [];
            unset($validated['id'], $validated['teacher_ids'], $validated['teachers']);

            if ($subject) {
                $subject->update($validated);
                $updated++;
            } else {
                $subject = Subject::create($validated);
                $created++;
            }

            $subject->teachers()->sync($teacherIds);
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
        $payload['teacher_ids'] = $this->normalizeTeacherIds($payload);

        return $payload;
    }

    private function normalizeTeacherIds(array $payload): array
    {
        $ids = collect(explode(',', (string) ($payload['teacher_ids'] ?? '')))
            ->map(fn (string $id) => trim($id))
            ->filter()
            ->map(fn (string $id) => is_numeric($id) ? (int) $id : $id)
            ->values();

        if ($ids->isNotEmpty()) {
            return $ids->all();
        }

        return collect(preg_split('/\s*\|\s*/u', (string) ($payload['teachers'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $name) => $this->findTeacherByName(trim($name))?->id ?? '__missing__')
            ->values()
            ->all();
    }

    private function findSubject(array $payload): ?Subject
    {
        if (!empty($payload['id'])) {
            return Subject::find($payload['id']);
        }

        if (!empty($payload['code'])) {
            $subject = Subject::where('code', $payload['code'])->first();

            if ($subject) {
                return $subject;
            }
        }

        return !empty($payload['name']) ? Subject::where('name', $payload['name'])->first() : null;
    }

    private function rules(?Subject $subject): array
    {
        return [
            'id' => ['nullable', 'integer', 'exists:subjects,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100', Rule::unique('subjects', 'code')->ignore($subject)],
            'department' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'teacher_ids' => ['array'],
            'teacher_ids.*' => ['integer', 'exists:teachers,id'],
            'teachers' => ['nullable', 'string'],
        ];
    }

    private function messages(): array
    {
        return [
            'id.exists' => 'Дисциплина с указанным id не найдена.',
            'name.required' => 'Не указано название дисциплины.',
            'code.unique' => 'Дисциплина с таким кодом уже существует.',
            'teacher_ids.*.integer' => 'Преподаватель не найден.',
            'teacher_ids.*.exists' => 'Преподаватель не найден.',
        ];
    }

    private function findTeacherByName(string $name): ?Teacher
    {
        return Teacher::query()
            ->get()
            ->first(fn (Teacher $teacher) => $this->teacherName($teacher) === $name);
    }

    private function teacherName(Teacher $teacher): string
    {
        return collect([$teacher->last_name, $teacher->first_name, $teacher->middle_name])
            ->filter()
            ->join(' ');
    }
}
