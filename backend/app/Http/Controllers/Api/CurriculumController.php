<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCurriculumItemRequest;
use App\Http\Requests\StoreCurriculumRequest;
use App\Http\Requests\UpdateCurriculumRequest;
use App\Http\Resources\CurriculumItemResource;
use App\Http\Resources\CurriculumSubjectResource;
use App\Http\Resources\CurriculumResource;
use App\Models\Curriculum;
use App\Models\CurriculumItem;
use App\Models\CurriculumSubject;
use App\Models\ReferenceItem;
use App\Models\EducationProgram;
use App\Models\Subject;
use App\Services\AuditLogService;
use App\Services\AutoCodeService;
use App\Services\CurriculumEngineService;
use App\Support\Csv\CsvExport;
use App\Support\Csv\CsvImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Support\Http\PageSize;

class CurriculumController extends Controller
{
    public function __construct(
        private readonly AutoCodeService $autoCodeService,
        private readonly CurriculumEngineService $curriculumEngine,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $curricula = Curriculum::query()
            ->with(['educationProgram.specialty', 'items.subject', 'subjects.subject', 'subjects.controlType'])
            ->withCount(['items', 'subjects'])
            ->when($request->integer('education_program_id'), fn ($query, int $id) => $query->where('education_program_id', $id))
            ->when($request->integer('year_start'), fn ($query, int $year) => $query->where('year_start', $year))
            ->when($request->integer('specialty_id'), function ($query, int $specialtyId): void {
                $query->whereHas('educationProgram', fn ($programQuery) => $programQuery->where('specialty_id', $specialtyId));
            })
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $search = mb_strtolower($search);
                $query->where(function ($query) use ($search): void {
                    $query->whereRaw('lower(name) like ?', ["%{$search}%"])
                        ->orWhereRaw('lower(description) like ?', ["%{$search}%"])
                        ->orWhereHas('educationProgram', fn ($programQuery) => $programQuery->whereRaw('lower(name) like ?', ["%{$search}%"]));
                });
            })
            ->orderByDesc('year_start')
            ->orderBy('name')
            ->paginate(PageSize::from($request, 50));

        return CurriculumResource::collection($curricula);
    }

    public function store(StoreCurriculumRequest $request): JsonResponse
    {
        $curriculum = Curriculum::create($this->validatedCurriculumData($request->validated()));

        return (new CurriculumResource($curriculum->load(['educationProgram.specialty', 'items.subject', 'subjects.subject', 'subjects.controlType'])->loadCount(['items', 'subjects'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Curriculum $curriculum): CurriculumResource
    {
        return new CurriculumResource($curriculum->load(['educationProgram.specialty', 'items.subject', 'subjects.subject', 'subjects.controlType'])->loadCount(['items', 'subjects']));
    }

    public function update(UpdateCurriculumRequest $request, Curriculum $curriculum): CurriculumResource
    {
        $curriculum->update($this->normalizedCurriculumPatch($request->validated()));

        return new CurriculumResource($curriculum->load(['educationProgram.specialty', 'items.subject', 'subjects.subject', 'subjects.controlType'])->loadCount(['items', 'subjects']));
    }

    public function destroy(Curriculum $curriculum): Response
    {
        $curriculum->delete();

        return response()->noContent();
    }

    public function storeItem(StoreCurriculumItemRequest $request, Curriculum $curriculum): JsonResponse
    {
        $item = $curriculum->items()->create($request->validated());

        return (new CurriculumItemResource($item->load('subject')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroyItem(CurriculumItem $curriculumItem): Response
    {
        $curriculumItem->delete();

        return response()->noContent();
    }

    public function subjects(Curriculum $curriculum): AnonymousResourceCollection
    {
        return CurriculumSubjectResource::collection($curriculum->subjects()->with(['subject', 'controlType'])->get());
    }

    public function semesters(Curriculum $curriculum): JsonResponse
    {
        $semesters = collect($this->curriculumEngine->semesters($curriculum))->map(function (array $semester): array {
            return [
                'semester' => $semester['semester'],
                'subjects_count' => $semester['subjects_count'],
                'total_hours' => $semester['total_hours'],
                'subjects' => CurriculumSubjectResource::collection($semester['subjects'])->resolve(),
            ];
        })->values();

        return response()->json(['data' => $semesters]);
    }

    public function summary(Curriculum $curriculum): JsonResponse
    {
        return response()->json(['data' => $this->curriculumEngine->summary($curriculum)]);
    }

    public function storeSubject(Request $request, Curriculum $curriculum): JsonResponse
    {
        $data = $this->validatedSubjectData($request);
        $subject = $curriculum->subjects()->create($data);
        AuditLogService::log('Curricula', 'curriculum_subject_created', $subject, null, $subject->getAttributes(), $request);

        return (new CurriculumSubjectResource($subject->load(['subject', 'controlType'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function updateSubject(Request $request, CurriculumSubject $curriculumSubject): CurriculumSubjectResource
    {
        $old = $curriculumSubject->getAttributes();
        $curriculumSubject->update($this->validatedSubjectData($request, partial: true, existing: $curriculumSubject));
        AuditLogService::log('Curricula', 'curriculum_subject_updated', $curriculumSubject, $old, $curriculumSubject->getAttributes(), $request);

        return new CurriculumSubjectResource($curriculumSubject->load(['subject', 'controlType']));
    }

    public function destroySubject(Request $request, CurriculumSubject $curriculumSubject): Response
    {
        $old = $curriculumSubject->getAttributes();
        AuditLogService::log('Curricula', 'curriculum_subject_deleted', $curriculumSubject, $old, null, $request);
        $curriculumSubject->delete();

        return response()->noContent();
    }

    public function export(): StreamedResponse
    {
        $filename = 'curricula-'.now()->format('Ymd-His').'.csv';
        $curricula = Curriculum::query()->with(['educationProgram.specialty', 'items.subject'])->orderBy('id')->get();

        return CsvExport::download($filename, ['id', 'code', 'education_program_id', 'program_name', 'specialty', 'year_start', 'name', 'status', 'description', 'subject_id', 'subject_code', 'subject_name', 'course', 'semester', 'hours_total', 'control_form', 'sort_order'], function (callable $row) use ($curricula): void {
            foreach ($curricula as $curriculum) {
                $items = $curriculum->items->isNotEmpty() ? $curriculum->items : collect([null]);
                foreach ($items as $item) {
                    $row([
                        $curriculum->id,
                        $curriculum->code,
                        $curriculum->education_program_id,
                        $curriculum->educationProgram?->name,
                        $curriculum->educationProgram?->specialty?->name,
                        $curriculum->year_start,
                        $curriculum->name,
                        $curriculum->status,
                        $curriculum->description,
                        $item?->subject_id,
                        $item?->subject?->code,
                        $item?->subject?->name,
                        $item?->course,
                        $item?->semester,
                        $item?->hours_total,
                        $item?->control_form,
                        $item?->sort_order,
                    ]);
                }
            }
        });
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $created = 0; $updated = 0; $itemsCreated = 0; $errors = [];

        foreach (CsvImport::rows($request->file('file')->getRealPath()) as $line => $data) {
            $validator = Validator::make($data, [
                'code' => ['nullable', 'string', 'max:100'],
                'education_program_id' => ['nullable', 'integer', 'exists:education_programs,id'],
                'program_name' => ['nullable', 'string'],
                'year_start' => ['required', 'integer', 'min:2000', 'max:2100'],
                'name' => ['required', 'string', 'max:255'],
                'status' => ['nullable', 'in:draft,active,archived'],
                'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
                'subject_code' => ['nullable', 'string'],
                'subject_name' => ['nullable', 'string'],
                'course' => ['nullable', 'integer', 'min:1', 'max:6'],
                'semester' => ['nullable', 'integer', 'min:1', 'max:12'],
                'hours_total' => ['nullable', 'integer', 'min:0', 'max:5000'],
            ]);
            if ($validator->fails()) { $errors[] = ['line' => $line, 'errors' => $validator->errors()->all()]; continue; }

            try {
                DB::transaction(function () use ($data, &$created, &$updated, &$itemsCreated): void {
                    $programId = $this->resolveProgramId($data);
                    if (!$programId) { throw new \RuntimeException('Образовательная программа не найдена.'); }
                    $payload = $this->validatedCurriculumData([
                        'code' => $data['code'] ?? null,
                        'education_program_id' => $programId,
                        'name' => $data['name'],
                        'year_start' => (int) $data['year_start'],
                        'status' => $data['status'] ?: 'draft',
                        'description' => $data['description'] ?? null,
                    ]);
                    $curriculum = !empty($data['id']) ? Curriculum::find($data['id']) : null;
                    if ($curriculum) { $curriculum->update($payload); $updated++; }
                    else { $curriculum = Curriculum::create($payload); $created++; }

                    $subjectId = $this->resolveSubjectId($data);
                    if ($subjectId && !empty($data['semester'])) {
                        // `curriculum_subjects`, а не `items`: нагрузка строится из
                        // неё, и план, загруженный в `items`, выглядел заполненным,
                        // а нагрузка видела пустоту.
                        //
                        // `firstOrNew` + `save`, а не `updateOrCreate`: последний
                        // внутри транзакции открывает точку сохранения на каждую
                        // строку, а таблица блокировок PostgreSQL одна на сервер —
                        // на массовой загрузке это упирается в `out of shared memory`.
                        $subject = $curriculum->subjects()->firstOrNew([
                            'subject_id' => $subjectId,
                            'semester' => (int) $data['semester'],
                        ]);
                        $isNew = ! $subject->exists;
                        $subject->fill([
                            'total_hours' => (int) ($data['hours_total'] ?: 0),
                            'control_type' => $data['control_form'] ?: null,
                            'sequence' => (int) ($data['sort_order'] ?: 0),
                        ])->save();
                        if ($isNew) { $itemsCreated++; }
                    }
                });
            } catch (\Throwable $exception) {
                $errors[] = ['line' => $line, 'errors' => [$exception->getMessage()]];
            }
        }

        return response()->json(['data' => compact('created', 'updated', 'itemsCreated', 'errors')]);
    }



    private function validatedSubjectData(Request $request, bool $partial = false, ?CurriculumSubject $existing = null): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'semester' => [$required, 'integer', 'min:1', 'max:12'],
            'subject_id' => [$required, 'integer', 'exists:subjects,id'],
            'lecture_hours' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'practice_hours' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'laboratory_hours' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'independent_hours' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'total_hours' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'control_type_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'control_type' => ['nullable', 'string', 'max:100'],
            'sequence' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_optional' => ['sometimes', 'boolean'],
            'competencies' => ['nullable', 'array'],
        ]);

        foreach (['lecture_hours', 'practice_hours', 'laboratory_hours', 'independent_hours', 'sequence'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (int) $data[$field];
            }
        }

        if (array_key_exists('control_type_id', $data) && $data['control_type_id']) {
            $data['control_type'] = ReferenceItem::query()->find($data['control_type_id'])?->code;
        }

        if (! array_key_exists('total_hours', $data) || $data['total_hours'] === null) {
            $data['total_hours'] = (int) ($data['lecture_hours'] ?? $existing?->lecture_hours ?? 0)
                + (int) ($data['practice_hours'] ?? $existing?->practice_hours ?? 0)
                + (int) ($data['laboratory_hours'] ?? $existing?->laboratory_hours ?? 0)
                + (int) ($data['independent_hours'] ?? $existing?->independent_hours ?? 0);
        } else {
            $data['total_hours'] = (int) $data['total_hours'];
        }

        if (array_key_exists('is_optional', $data)) {
            $data['is_optional'] = (bool) $data['is_optional'];
        }

        return $data;
    }

    private function normalizedCurriculumPatch(array $data): array
    {
        $patch = [];
        foreach (['code', 'education_program_id', 'name', 'qualification', 'year_start', 'status', 'description', 'competencies'] as $field) {
            if (array_key_exists($field, $data)) {
                $patch[$field] = $field === 'name' ? trim($data[$field]) : $data[$field];
            }
        }
        if (array_key_exists('education_program_id', $patch)) { $patch['education_program_id'] = (int) $patch['education_program_id']; }
        if (array_key_exists('year_start', $patch)) { $patch['year_start'] = (int) $patch['year_start']; }
        if (array_key_exists('status', $patch) && !$patch['status']) { $patch['status'] = 'draft'; }
        if (array_key_exists('code', $patch) && ! $patch['code']) { $patch['code'] = $this->autoCodeService->curriculumCode($patch['name'] ?? null); }
        return $patch;
    }

    private function validatedCurriculumData(array $data): array
    {
        return [
            'code' => ($data['code'] ?? null) ?: $this->autoCodeService->curriculumCode($data['name'] ?? null),
            'education_program_id' => (int) $data['education_program_id'],
            'name' => trim($data['name']),
            'qualification' => $data['qualification'] ?? null,
            'year_start' => (int) $data['year_start'],
            'status' => $data['status'] ?: 'draft',
            'description' => $data['description'] ?? null,
            'competencies' => $data['competencies'] ?? null,
        ];
    }

    private function resolveProgramId(array $data): ?int
    {
        if (!empty($data['education_program_id'])) { return (int) $data['education_program_id']; }
        if (!empty($data['program_name'])) { return EducationProgram::where('name', $data['program_name'])->value('id'); }
        return null;
    }

    private function resolveSubjectId(array $data): ?int
    {
        if (!empty($data['subject_id'])) { return (int) $data['subject_id']; }
        if (!empty($data['subject_code'])) { return Subject::where('code', $data['subject_code'])->value('id'); }
        if (!empty($data['subject_name'])) { return Subject::where('name', $data['subject_name'])->value('id'); }
        return null;
    }
}
