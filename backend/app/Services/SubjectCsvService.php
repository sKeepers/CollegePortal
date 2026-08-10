<?php

namespace App\Services;

use App\Models\Subject;
use App\Models\Teacher;
use App\Services\Import\SubjectImportHandler;
use App\Support\Csv\CsvExport;
use App\Support\Csv\CsvImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubjectCsvService
{
    /** Колонки, которые принимает собственный CSV-импорт дисциплин. */
    private const HEADERS = [
        'id',
        'name',
        'code',
        'department',
        'description',
        'teacher_ids',
        'teachers',
    ];

    /**
     * Заголовки шаблона импорта в технические имена этого сервиса — то же
     * решение, что и у преподавателей: файл отдают как заготовку, и он обязан
     * приниматься там, откуда его взяли. Прежние машинные заголовки продолжают
     * работать, файлы по старому образцу никуда не делись.
     */
    private const LABEL_TO_COLUMN = [
        'дисциплина' => 'name',
        'название' => 'name',
        'код' => 'code',
        'отделение' => 'department',
        'кафедра' => 'department',
        'описание' => 'description',
        'преподаватели' => 'teachers',
        'преподаватель' => 'teachers',
    ];

    /**
     * Выгрузка идёт колонками шаблона импорта, а не машинными именами полей.
     *
     * Идентификатор из выгрузки убран намеренно: это не данные, а порядок
     * выдачи строк, и дисциплина находится по коду — он же ключ импорта.
     * Преподаватели выгружаются одной колонкой с ФИО: список идентификаторов
     * владелец в Excel не отредактирует.
     */
    public function export(): StreamedResponse
    {
        $handler = app(SubjectImportHandler::class);

        return CsvExport::download('subjects.csv', $handler->templateHeaders(), function (callable $row): void {
            Subject::query()
                ->with('teachers')
                ->orderBy('name')
                ->chunk(200, function ($subjects) use ($row): void {
                    foreach ($subjects as $subject) {
                        // Порядок обязан совпадать с templateHeaders() обработчика импорта.
                        $row([
                            $subject->name,
                            $subject->code,
                            $subject->department,
                            $subject->description,
                            $subject->teachers->map(fn (Teacher $teacher) => $this->teacherName($teacher))->join(' | '),
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
            $subject = $this->findSubject($payload);
            $validator = Validator::make($payload, $this->rules($subject), $this->messages());

            if ($validator->fails()) {
                $errors[] = [
                    'line' => $line,
                    'messages' => $validator->errors()->all(),
                ];
                continue;
            }

            $resolved = app(SubjectImportHandler::class)->teachersFromColumn($payload['teachers'] ?? null);

            if ($resolved['unresolved'] !== []) {
                $errors[] = [
                    'line' => $line,
                    'messages' => array_map(
                        static fn (string $name): string => "Преподаватель не найден однозначно: {$name}. Уточните ФИО полностью или укажите идентификатор.",
                        $resolved['unresolved']
                    ),
                ];
                continue;
            }

            $validated = $validator->validated();
            unset($validated['id'], $validated['teachers']);

            if ($subject) {
                $subject->update($validated);
                $updated++;
            } else {
                $subject = Subject::create($validated);
                $created++;
            }

            // Колонки преподавателей в файле не было — связь не трогаем.
            // Раньше её отсутствие молча отвязывало всех: файл без этой колонки
            // стирал привязку по всему реестру, и ничто об этом не сообщало.
            if (($payload['teachers'] ?? null) !== null) {
                $subject->teachers()->sync($resolved['ids']);
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
        // Считать преподавателей нужно до превращения пустых значений в null:
        // дальше «колонка есть, ячейка пуста» уже неотличимо от «колонки нет»,
        // а это разные вещи — очистить связь и не трогать её.
        $teachers = $this->teachersColumn($payload);
        $payload = array_map(fn ($value) => $value === '' ? null : $value, $payload);
        unset($payload['teacher_ids']);
        $payload['teachers'] = $teachers;

        return $payload;
    }

    /**
     * Преподаватели приходят либо колонкой «Преподаватели» с ФИО, либо прежней
     * `teacher_ids` с идентификаторами. Обе сводятся к одной строке, разбирает
     * её обработчик универсального импорта.
     */
    private function teachersColumn(array $payload): ?string
    {
        $ids = trim((string) ($payload['teacher_ids'] ?? ''));

        if ($ids !== '') {
            return $ids;
        }

        if (array_key_exists('teachers', $payload)) {
            return trim((string) $payload['teachers']);
        }

        return array_key_exists('teacher_ids', $payload) ? '' : null;
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
            // Преподаватели проверяются не здесь: имя разбирается обработчиком
            // импорта, и «не нашёлся» с «нашлось несколько» — разные сообщения.
            'teachers' => ['nullable', 'string'],
        ];
    }

    private function messages(): array
    {
        return [
            'id.exists' => 'Дисциплина с указанным id не найдена.',
            'name.required' => 'Не указано название дисциплины.',
            'code.unique' => 'Дисциплина с таким кодом уже существует.',
        ];
    }

    private function teacherName(Teacher $teacher): string
    {
        return collect([$teacher->last_name, $teacher->first_name, $teacher->middle_name])
            ->filter()
            ->join(' ');
    }
}
