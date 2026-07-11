<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduleEntryResource;
use App\Models\ScheduleEntry;
use App\Models\ScheduleTemplate;
use App\Services\ScheduleEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ScheduleEngineController extends Controller
{
    public function __construct(private readonly ScheduleEngineService $scheduleEngineService)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return ScheduleEntryResource::collection(
            $this->scheduleEngineService->query($request->query())->paginate((int) $request->query('per_page', 50))
        );
    }

    public function preview(Request $request): JsonResponse
    {
        return response()->json($this->scheduleEngineService->preview($this->validatedEntry($request)));
    }

    public function validateEntry(Request $request): JsonResponse
    {
        return response()->json($this->scheduleEngineService->preview($this->validatedEntry($request)));
    }

    public function apply(Request $request): JsonResponse
    {
        $result = $this->scheduleEngineService->apply($this->validatedEntry($request), $request->user());
        $entry = $result['entry'];
        $result['entry'] = new ScheduleEntryResource($entry);

        return response()->json($result, Response::HTTP_CREATED);
    }

    public function conflicts(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->scheduleEngineService->conflicts($request->query())]);
    }

    public function coverage(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->scheduleEngineService->coverage($request->query())]);
    }

    public function group(int $groupId, Request $request): AnonymousResourceCollection
    {
        return ScheduleEntryResource::collection($this->scheduleEngineService->query([...$request->query(), 'group_id' => $groupId])->get());
    }

    public function teacher(int $teacherId, Request $request): AnonymousResourceCollection
    {
        return ScheduleEntryResource::collection($this->scheduleEngineService->query([...$request->query(), 'teacher_id' => $teacherId])->get());
    }

    public function classroom(int $classroomId, Request $request): AnonymousResourceCollection
    {
        return ScheduleEntryResource::collection($this->scheduleEngineService->query([...$request->query(), 'classroom_id' => $classroomId])->get());
    }

    public function replaceTeacher(ScheduleEntry $scheduleEntry, Request $request): ScheduleEntryResource
    {
        $data = $request->validate(['teacher_id' => ['required', 'integer', 'exists:teachers,id']]);
        return new ScheduleEntryResource($this->scheduleEngineService->replaceTeacher($scheduleEntry, (int) $data['teacher_id'], $request->user()));
    }

    public function replaceClassroom(ScheduleEntry $scheduleEntry, Request $request): ScheduleEntryResource
    {
        $data = $request->validate(['classroom_id' => ['nullable', 'integer', 'exists:classrooms,id']]);
        return new ScheduleEntryResource($this->scheduleEngineService->replaceClassroom($scheduleEntry, isset($data['classroom_id']) ? (int) $data['classroom_id'] : null, $request->user()));
    }

    public function move(ScheduleEntry $scheduleEntry, Request $request): ScheduleEntryResource
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'lesson_number' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);
        return new ScheduleEntryResource($this->scheduleEngineService->move($scheduleEntry, $data, $request->user()));
    }

    public function cancel(ScheduleEntry $scheduleEntry, Request $request): ScheduleEntryResource
    {
        return new ScheduleEntryResource($this->scheduleEngineService->cancel($scheduleEntry, $request->user()));
    }

    public function restore(ScheduleEntry $scheduleEntry, Request $request): ScheduleEntryResource
    {
        return new ScheduleEntryResource($this->scheduleEngineService->restore($scheduleEntry, $request->user()));
    }


    public function templates(Request $request): JsonResponse
    {
        $templates = ScheduleTemplate::query()
            ->with(['group', 'entries.subject', 'entries.teacher', 'entries.classroom'])
            ->when($request->integer('group_id'), fn ($query, int $groupId) => $query->where('group_id', $groupId))
            ->when($request->query('academic_year'), fn ($query, string $year) => $query->where('academic_year', $year))
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $templates]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester' => ['required', 'integer', 'min:1', 'max:12'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'week_type' => ['nullable', Rule::in(['all', 'even', 'odd'])],
            'status' => ['nullable', Rule::in(['draft', 'active', 'archived'])],
            'entries' => ['array'],
            'entries.*.day_of_week' => ['required', 'integer', 'min:1', 'max:7'],
            'entries.*.week_type' => ['nullable', Rule::in(['all', 'even', 'odd'])],
            'entries.*.lesson_number' => ['required', 'integer', 'min:1', 'max:12'],
            'entries.*.starts_at' => ['required', 'date_format:H:i'],
            'entries.*.ends_at' => ['required', 'date_format:H:i'],
            'entries.*.subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'entries.*.teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'entries.*.classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
            'entries.*.teaching_load_item_id' => ['nullable', 'integer', 'exists:teaching_load_items,id'],
            'entries.*.lesson_type_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'entries.*.comment' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->scheduleEngineService->createTemplate($data, $request->user())], Response::HTTP_CREATED);
    }

    public function templateApplyPreview(ScheduleTemplate $scheduleTemplate, Request $request): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        return response()->json($this->scheduleEngineService->applyTemplatePreview($scheduleTemplate, $data['date_from'], $data['date_to']));
    }

    public function templateApply(ScheduleTemplate $scheduleTemplate, Request $request): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        return response()->json($this->scheduleEngineService->applyTemplateConfirm($scheduleTemplate, $data['date_from'], $data['date_to'], $request->user()));
    }

    private function validatedEntry(Request $request): array
    {
        return $request->validate([
            'academic_year' => ['nullable', 'string', 'max:20'],
            'semester' => ['nullable', 'integer', 'min:1', 'max:12'],
            'date' => ['nullable', 'date'],
            'lesson_date' => ['nullable', 'date'],
            'day_of_week' => ['nullable', 'integer', 'min:1', 'max:7'],
            'week_type' => ['nullable', Rule::in(['even', 'odd', 'all'])],
            'lesson_number' => ['nullable', 'integer', 'min:1', 'max:12'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
            'teaching_load_item_id' => ['nullable', 'integer', 'exists:teaching_load_items,id'],
            'lesson_type_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'status' => ['nullable', Rule::in(['draft', 'scheduled', 'canceled', 'moved'])],
            'source' => ['nullable', 'string', 'max:50'],
            'is_replacement' => ['nullable', 'boolean'],
            'replaced_entry_id' => ['nullable', 'integer', 'exists:schedule_entries,id'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'topic' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
