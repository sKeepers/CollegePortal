<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTeachingLoadItemRequest;
use App\Http\Requests\StoreTeachingLoadRequest;
use App\Http\Requests\UpdateTeachingLoadRequest;
use App\Http\Resources\TeachingLoadItemResource;
use App\Http\Resources\TeachingLoadResource;
use App\Models\Group;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingLoad;
use App\Models\TeachingLoadItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use App\Services\AuditLogService;
use App\Services\TeachingLoadGenerationService;
use App\Support\Csv\CsvExport;
use App\Support\Csv\CsvImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeachingLoadController extends Controller
{
    public function __construct(private readonly TeachingLoadGenerationService $generationService)
    {
    }


    public function generatePreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'academic_year' => ['required', 'string', 'max:20'],
        ]);

        return response()->json(['data' => $this->generationService->preview((int) $data['group_id'], trim($data['academic_year']))]);
    }

    public function generateApply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'academic_year' => ['required', 'string', 'max:20'],
        ]);

        return response()->json(['message' => 'Нагрузка сформирована из учебного плана.', 'data' => $this->generationService->apply((int) $data['group_id'], trim($data['academic_year']), $request->user())]);
    }

    public function coverage(TeachingLoad $teachingLoad): JsonResponse
    {
        return response()->json(['data' => $this->generationService->coverage($teachingLoad)]);
    }

    public function assignTeacher(Request $request, TeachingLoadItem $teachingLoadItem): TeachingLoadItemResource
    {
        $data = $request->validate([
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'assigned_hours' => ['nullable', 'integer', 'min:0', 'max:20000'],
        ]);

        $this->warnIfTeacherDoesNotTeachSubject((int) $data['teacher_id'], $teachingLoadItem);

        return new TeachingLoadItemResource($this->generationService->assignTeacher($teachingLoadItem, (int) $data['teacher_id'], $data['assigned_hours'] ?? null, $request->user()));
    }

    public function bulkAssignTeacher(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:teaching_load_items,id'],
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'assigned_hours' => ['nullable', 'integer', 'min:0', 'max:20000'],
        ]);

        return response()->json(['message' => 'Преподаватель назначен выбранным строкам.', 'data' => $this->generationService->bulkAssignTeacher($data['ids'], (int) $data['teacher_id'], $data['assigned_hours'] ?? null, $request->user())]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TeachingLoad::query()
            ->with(['teacher', 'curriculum', 'group', 'items.subject', 'items.group', 'items.teacher', 'items.curriculumSubject', 'items.workloadType'])
            ->withCount('items')
            ->when($request->string('academic_year')->toString(), fn ($query, string $year) => $query->where('academic_year', $year))
            ->when($request->integer('teacher_id'), fn ($query, int $id) => $query->where('teacher_id', $id))
            ->when($request->integer('group_id'), fn ($query, int $id) => $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('group_id', $id)))
            ->when($request->integer('subject_id'), fn ($query, int $id) => $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('subject_id', $id)))
            ->when($request->integer('semester'), fn ($query, int $semester) => $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('semester', $semester)))
            ->when($request->integer('assignment_teacher_id'), fn ($query, int $id) => $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('teacher_id', $id)))
            ->when($request->string('assignment_status')->toString(), fn ($query, string $status) => $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('assignment_status', $status)))
            ->orderByDesc('academic_year')
            ->orderBy('teacher_id');

        if (! $request->user()->hasPermission('teachingload.view')) {
            $teacherId = $request->user()->teacher()->value('id');

            // A teacher account that is not linked to a Teacher profile is still authorized here:
            // it simply owns no load. Return an empty page instead of a misleading permission error.
            $query->when(
                $teacherId === null,
                fn ($loadQuery) => $loadQuery->whereRaw('1 = 0'),
                fn ($loadQuery) => $loadQuery->where(fn ($ownQuery) => $ownQuery
                    ->where('teacher_id', $teacherId)
                    ->orWhereHas('items', fn ($itemQuery) => $itemQuery->where('teacher_id', $teacherId)))
            );
        }

        $loads = $query->paginate(50);

        return TeachingLoadResource::collection($loads);
    }

    public function store(StoreTeachingLoadRequest $request): JsonResponse
    {
        $load = TeachingLoad::create($this->normalizeLoadData($request->validated()));

        return (new TeachingLoadResource($load->load(['teacher', 'curriculum', 'group', 'items.subject', 'items.group', 'items.teacher', 'items.curriculumSubject', 'items.workloadType'])->loadCount('items')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(TeachingLoad $teachingLoad): TeachingLoadResource
    {
        return new TeachingLoadResource($teachingLoad->load(['teacher', 'curriculum', 'group', 'items.subject', 'items.group', 'items.teacher', 'items.curriculumSubject', 'items.workloadType'])->loadCount('items'));
    }

    public function update(UpdateTeachingLoadRequest $request, TeachingLoad $teachingLoad): TeachingLoadResource
    {
        $teachingLoad->update($this->normalizeLoadPatch($request->validated()));

        return new TeachingLoadResource($teachingLoad->load(['teacher', 'curriculum', 'group', 'items.subject', 'items.group', 'items.teacher', 'items.curriculumSubject', 'items.workloadType'])->loadCount('items'));
    }

    public function destroy(TeachingLoad $teachingLoad): Response
    {
        $teachingLoad->delete();

        return response()->noContent();
    }

    public function storeItem(StoreTeachingLoadItemRequest $request, TeachingLoad $teachingLoad): JsonResponse
    {
        $item = $teachingLoad->items()->create($request->validated());

        return (new TeachingLoadItemResource($item->load(['subject', 'group', 'teacher', 'curriculumSubject', 'workloadType'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroyItem(TeachingLoadItem $teachingLoadItem): Response
    {
        $teachingLoadItem->delete();

        return response()->noContent();
    }

    public function export(): StreamedResponse
    {
        $loads = TeachingLoad::query()->with(['teacher', 'curriculum', 'group', 'items.subject', 'items.group', 'items.teacher', 'items.curriculumSubject', 'items.workloadType'])->orderBy('id')->get();
        $filename = 'teaching-loads-'.now()->format('Ymd-His').'.csv';

        return CsvExport::download($filename, ['id', 'academic_year', 'teacher_id', 'teacher', 'status', 'description', 'subject_id', 'subject_code', 'subject_name', 'group_id', 'group_name', 'semester', 'hours_total', 'load_type', 'sort_order'], function (callable $row) use ($loads): void {
            foreach ($loads as $load) {
                $items = $load->items->isNotEmpty() ? $load->items : collect([null]);
                foreach ($items as $item) {
                    $row([
                        $load->id,
                        $load->academic_year,
                        $load->teacher_id,
                        $this->teacherName($load->teacher),
                        $load->status,
                        $load->description,
                        $item?->subject_id,
                        $item?->subject?->code,
                        $item?->subject?->name,
                        $item?->group_id,
                        $item?->group?->name,
                        $item?->semester,
                        $item?->hours_total,
                        $item?->load_type,
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
                'academic_year' => ['required', 'string', 'max:20'],
                'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
                'teacher' => ['nullable', 'string'],
                'status' => ['nullable', 'in:draft,active,archived'],
                'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
                'subject_code' => ['nullable', 'string'],
                'subject_name' => ['nullable', 'string'],
                'group_id' => ['nullable', 'integer', 'exists:groups,id'],
                'group_name' => ['nullable', 'string'],
                'semester' => ['nullable', 'integer', 'min:1', 'max:12'],
                'hours_total' => ['nullable', 'integer', 'min:0', 'max:5000'],
                'load_type' => ['nullable', 'string', 'max:100'],
            ]);
            if ($validator->fails()) { $errors[] = ['line' => $line, 'errors' => $validator->errors()->all()]; continue; }

            try {
                DB::transaction(function () use ($data, &$created, &$updated, &$itemsCreated): void {
                    $teacherId = $this->resolveTeacherId($data);
                    if (!$teacherId) { throw new \RuntimeException('Преподаватель не найден.'); }
                    $payload = $this->normalizeLoadData([
                        'academic_year' => $data['academic_year'],
                        'teacher_id' => $teacherId,
                        'status' => $data['status'] ?: 'draft',
                        'description' => $data['description'] ?? null,
                    ]);
                    $load = !empty($data['id']) ? TeachingLoad::find($data['id']) : null;
                    if ($load) { $load->update($payload); $updated++; }
                    else { $load = TeachingLoad::create($payload); $created++; }

                    $subjectId = $this->resolveSubjectId($data);
                    $groupId = $this->resolveGroupId($data);
                    if ($subjectId && $groupId && !empty($data['semester'])) {
                        $item = $load->items()->updateOrCreate(
                            ['subject_id' => $subjectId, 'group_id' => $groupId, 'semester' => (int) $data['semester'], 'load_type' => $data['load_type'] ?: 'Аудиторная'],
                            ['hours_total' => (int) ($data['hours_total'] ?: 0), 'sort_order' => (int) ($data['sort_order'] ?: 0)]
                        );
                        if ($item->wasRecentlyCreated) { $itemsCreated++; }
                    }
                });
            } catch (\Throwable $exception) {
                $errors[] = ['line' => $line, 'errors' => [$exception->getMessage()]];
            }
        }

        return response()->json(['data' => compact('created', 'updated', 'itemsCreated', 'errors')]);
    }


    private function warnIfTeacherDoesNotTeachSubject(int $teacherId, TeachingLoadItem $item): void
    {
        $teacher = Teacher::query()->with('subjects')->find($teacherId);
        if ($teacher && method_exists($teacher, 'subjects') && $teacher->subjects->isNotEmpty() && ! $teacher->subjects->contains('id', $item->subject_id)) {
            AuditLogService::log('Teaching Load', 'teaching_load_assignment_subject_warning', $item, null, ['teacher_id' => $teacherId, 'subject_id' => $item->subject_id]);
        }
    }

    private function normalizeLoadData(array $data): array
    {
        return [
            'academic_year' => trim($data['academic_year']),
            'teacher_id' => isset($data['teacher_id']) && $data['teacher_id'] !== '' ? (int) $data['teacher_id'] : null,
            'curriculum_id' => isset($data['curriculum_id']) && $data['curriculum_id'] !== '' ? (int) $data['curriculum_id'] : null,
            'group_id' => isset($data['group_id']) && $data['group_id'] !== '' ? (int) $data['group_id'] : null,
            'status' => $data['status'] ?: 'draft',
            'description' => $data['description'] ?? null,
        ];
    }

    private function normalizeLoadPatch(array $data): array
    {
        $patch = [];
        foreach (['academic_year', 'teacher_id', 'curriculum_id', 'group_id', 'status', 'description'] as $field) {
            if (array_key_exists($field, $data)) { $patch[$field] = $field === 'academic_year' ? trim($data[$field]) : $data[$field]; }
        }
        if (array_key_exists('teacher_id', $patch)) { $patch['teacher_id'] = $patch['teacher_id'] === null || $patch['teacher_id'] === '' ? null : (int) $patch['teacher_id']; }
        if (array_key_exists('curriculum_id', $patch)) { $patch['curriculum_id'] = $patch['curriculum_id'] === null || $patch['curriculum_id'] === '' ? null : (int) $patch['curriculum_id']; }
        if (array_key_exists('group_id', $patch)) { $patch['group_id'] = $patch['group_id'] === null || $patch['group_id'] === '' ? null : (int) $patch['group_id']; }
        if (array_key_exists('status', $patch) && !$patch['status']) { $patch['status'] = 'draft'; }
        return $patch;
    }

    private function teacherName(?Teacher $teacher): string
    {
        return trim(implode(' ', array_filter([$teacher?->last_name, $teacher?->first_name, $teacher?->middle_name])));
    }

    private function resolveTeacherId(array $data): ?int
    {
        if (!empty($data['teacher_id'])) { return (int) $data['teacher_id']; }
        if (!empty($data['teacher'])) {
            $name = mb_strtolower(trim($data['teacher']));
            return Teacher::query()->get()->first(fn (Teacher $teacher) => mb_strtolower($this->teacherName($teacher)) === $name)?->id;
        }
        return null;
    }

    private function resolveSubjectId(array $data): ?int
    {
        if (!empty($data['subject_id'])) { return (int) $data['subject_id']; }
        if (!empty($data['subject_code'])) { return Subject::where('code', $data['subject_code'])->value('id'); }
        if (!empty($data['subject_name'])) { return Subject::where('name', $data['subject_name'])->value('id'); }
        return null;
    }

    private function resolveGroupId(array $data): ?int
    {
        if (!empty($data['group_id'])) { return (int) $data['group_id']; }
        if (!empty($data['group_name'])) { return Group::where('name', $data['group_name'])->value('id'); }
        return null;
    }
}
