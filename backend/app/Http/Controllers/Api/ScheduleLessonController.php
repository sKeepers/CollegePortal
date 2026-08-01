<?php

namespace App\Http\Controllers\Api;

use App\DTO\ScheduleLessonData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduleLessonRequest;
use App\Http\Requests\UpdateScheduleLessonRequest;
use App\Http\Resources\ScheduleLessonResource;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\ScheduleLessonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ScheduleLessonController extends Controller
{
    public function __construct(
        private readonly ScheduleLessonService $scheduleLessonService,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $lessons = ScheduleLesson::query()
            ->with(['group', 'teacher', 'subject', 'classroom'])
            ->when($request->integer('group_id'), fn ($query, int $groupId) => $query->where('group_id', $groupId))
            ->when($request->integer('teacher_id'), fn ($query, int $teacherId) => $query->where('teacher_id', $teacherId))
            ->when($request->integer('subject_id'), fn ($query, int $subjectId) => $query->where('subject_id', $subjectId))
            ->when($request->integer('classroom_id'), fn ($query, int $classroomId) => $query->where('classroom_id', $classroomId))
            ->when($request->query('date'), fn ($query, string $date) => $query->whereDate('lesson_date', $date))
            ->when($request->query('date_from'), fn ($query, string $date) => $query->whereDate('lesson_date', '>=', $date))
            ->when($request->query('date_to'), fn ($query, string $date) => $query->whereDate('lesson_date', '<=', $date))
            ->tap(fn ($query) => $this->applyScope($query, $request))
            ->orderBy('lesson_date')
            ->orderBy('starts_at')
            ->paginate(min(200, max(1, (int) $request->query('per_page', 50))));

        return ScheduleLessonResource::collection($lessons);
    }

    private function applyScope($query, Request $request): void
    {
        $user = $request->user();

        if ($user->hasRole('admin') || $user->hasPermission('schedule.update')) {
            return;
        }

        if ($teacherId = Teacher::query()->where('user_id', $user->id)->value('id')) {
            $query->where('teacher_id', $teacherId);
            return;
        }

        if ($student = Student::query()->where('user_id', $user->id)->first()) {
            $query->where('group_id', $student->group_id);
        }
    }

    public function store(StoreScheduleLessonRequest $request): JsonResponse
    {
        $lesson = $this->scheduleLessonService->create(ScheduleLessonData::fromArray($request->validated()));

        return (new ScheduleLessonResource($lesson->load(['group', 'teacher', 'subject', 'classroom'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ScheduleLesson $scheduleLesson): ScheduleLessonResource
    {
        return new ScheduleLessonResource($scheduleLesson->load(['group', 'teacher', 'subject', 'classroom']));
    }

    public function update(UpdateScheduleLessonRequest $request, ScheduleLesson $scheduleLesson): ScheduleLessonResource
    {
        $lesson = $this->scheduleLessonService->update(
            $scheduleLesson,
            ScheduleLessonData::fromArray($request->mergedWithCurrentLesson())
        );

        return new ScheduleLessonResource($lesson->load(['group', 'teacher', 'subject', 'classroom']));
    }

    public function destroy(ScheduleLesson $scheduleLesson): Response
    {
        $scheduleLesson->delete();

        return response()->noContent();
    }
}
