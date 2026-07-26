<?php

namespace App\Services;

use App\Models\ApplicantApplication;
use App\Models\EducationProgram;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicantApplicationCsvService
{
    public function __construct(
        private readonly ApplicantApplicationEventService $eventService,
        private readonly ApplicantApplicationDocumentService $documentService,
    ) {
    }

    private const HEADERS = [
        'id',
        'education_program_id',
        'education_program',
        'specialty_code',
        'last_name',
        'first_name',
        'middle_name',
        'birth_date',
        'phone',
        'email',
        'education_base',
        'status',
        'submitted_at',
        'comment',
    ];

    public function export(Request $request): StreamedResponse
    {
        return response()->streamDownload(function () use ($request): void {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, self::HEADERS, ';');

            $this->query($request)
                ->chunk(200, function ($applications) use ($output): void {
                    foreach ($applications as $application) {
                        fputcsv($output, [
                            $application->id,
                            $application->education_program_id,
                            $application->educationProgram?->name,
                            $application->educationProgram?->specialty?->code,
                            $application->last_name,
                            $application->first_name,
                            $application->middle_name,
                            $application->birth_date?->toDateString(),
                            $application->phone,
                            $application->email,
                            $application->education_base,
                            $application->status,
                            $application->submitted_at?->toDateString(),
                            $application->comment,
                        ], ';');
                    }
                });

            fclose($output);
        }, 'applicant-applications.csv', [
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
            $application = $this->findApplication($payload);
            $validator = Validator::make($payload, $this->rules(), $this->messages());

            if ($validator->fails()) {
                $errors[] = [
                    'line' => $line,
                    'messages' => $validator->errors()->all(),
                ];
                continue;
            }

            $validated = $validator->validated();
            unset($validated['id'], $validated['education_program'], $validated['specialty_code']);

            if ($application) {
                $oldStatus = $application->status;
                $application->update($validated);
                $this->eventService->record(
                    $application,
                    'imported',
                    'Обновлено из CSV',
                    'Заявление обновлено при импорте CSV.',
                    ['line' => $line, 'status_from' => $oldStatus, 'status_to' => $application->status],
                );
                $updated++;
            } else {
                $application = ApplicantApplication::create($validated);
                $this->documentService->ensureDefaultDocuments($application);
                $this->eventService->record(
                    $application,
                    'imported',
                    'Создано из CSV',
                    'Заявление создано при импорте CSV.',
                    ['line' => $line],
                );
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

    private function query(Request $request)
    {
        return ApplicantApplication::query()
            ->legacy()
            ->with('educationProgram.specialty')
            ->when($request->integer('education_program_id'), fn ($query, int $programId) => $query->where('education_program_id', $programId))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->string('education_base')->toString(), fn ($query, string $base) => $query->where('education_base', $base))
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $operator = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

                $query->where(function ($query) use ($operator, $search): void {
                    $query
                        ->where('last_name', $operator, "%{$search}%")
                        ->orWhere('first_name', $operator, "%{$search}%")
                        ->orWhere('middle_name', $operator, "%{$search}%")
                        ->orWhere('phone', $operator, "%{$search}%")
                        ->orWhere('email', $operator, "%{$search}%");
                });
            })
            ->orderByDesc('submitted_at')
            ->orderBy('last_name');
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

        if (empty($payload['education_program_id']) && !empty($payload['education_program'])) {
            $payload['education_program_id'] = EducationProgram::query()
                ->with('specialty')
                ->get()
                ->first(fn (EducationProgram $program) => $this->programMatches($program, $payload))
                ?->id ?? '__missing__';
        }

        $payload['birth_date'] = $this->normalizeDate($payload['birth_date'] ?? null);
        $payload['submitted_at'] = $this->normalizeDate($payload['submitted_at'] ?? null);
        $payload['education_base'] = $this->normalizeEducationBase($payload['education_base'] ?? null);
        $payload['status'] = $this->normalizeStatus($payload['status'] ?? null);

        return $payload;
    }

    private function programMatches(EducationProgram $program, array $payload): bool
    {
        $sameName = mb_strtolower($program->name) === mb_strtolower((string) $payload['education_program']);
        $sameSpecialty = empty($payload['specialty_code']) || $program->specialty?->code === $payload['specialty_code'];

        return $sameName && $sameSpecialty;
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

    private function normalizeEducationBase(?string $value): string|null
    {
        if ($value === null) {
            return 'after_9';
        }

        return match (mb_strtolower($value)) {
            'after_9', '9', 'после 9', 'после 9 класса' => 'after_9',
            'after_11', '11', 'после 11', 'после 11 класса' => 'after_11',
            default => $value,
        };
    }

    private function normalizeStatus(?string $value): string|null
    {
        if ($value === null) {
            return 'new';
        }

        return match (mb_strtolower($value)) {
            'new', 'новое', 'новый' => 'new',
            'accepted', 'принято', 'принят' => 'accepted',
            'needs_clarification', 'требуется уточнение', 'уточнение' => 'needs_clarification',
            'rejected', 'отклонено', 'отклонен' => 'rejected',
            'enrolled', 'зачислен', 'зачислена' => 'enrolled',
            default => $value,
        };
    }

    private function findApplication(array $payload): ?ApplicantApplication
    {
        if (!empty($payload['id'])) {
            return ApplicantApplication::query()->legacy()->find($payload['id']);
        }

        if (empty($payload['email'])) {
            return null;
        }

        return ApplicantApplication::query()->legacy()->where('email', $payload['email'])->first();
    }

    private function rules(): array
    {
        return [
            'id' => [
                'nullable',
                'integer',
                Rule::exists('applicant_applications', 'id')->where('record_type', ApplicantApplication::RECORD_TYPE_LEGACY),
            ],
            'education_program_id' => ['required', 'integer', 'exists:education_programs,id'],
            'education_program' => ['nullable', 'string', 'max:255'],
            'specialty_code' => ['nullable', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'education_base' => ['required', Rule::in(['after_9', 'after_11'])],
            'status' => ['required', Rule::in(['new', 'accepted', 'needs_clarification', 'rejected', 'enrolled'])],
            'submitted_at' => ['required', 'date'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function messages(): array
    {
        return [
            'id.exists' => 'Заявление с указанным id не найдено.',
            'education_program_id.required' => 'Не указана образовательная программа. Заполните education_program_id или education_program.',
            'education_program_id.integer' => 'Образовательная программа не найдена.',
            'education_program_id.exists' => 'Образовательная программа не найдена.',
            'last_name.required' => 'Не указана фамилия.',
            'first_name.required' => 'Не указано имя.',
            'birth_date.date' => 'Дата рождения должна быть в формате 2026-06-25 или 25.06.2026.',
            'email.email' => 'Email указан некорректно.',
            'education_base.in' => 'База поступления должна быть after_9 или after_11.',
            'status.in' => 'Статус должен быть new, accepted, needs_clarification, rejected или enrolled.',
            'submitted_at.required' => 'Не указана дата подачи заявления.',
            'submitted_at.date' => 'Дата подачи должна быть в формате 2026-06-25 или 25.06.2026.',
        ];
    }
}
