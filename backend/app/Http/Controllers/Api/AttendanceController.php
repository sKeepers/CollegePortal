<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttendanceRequest;
use App\Http\Requests\UpdateAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Services\JournalEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $attendance = Attendance::query()
            ->with(['scheduleLesson', 'student'])
            ->when($request->integer('schedule_lesson_id'), fn ($query, int $lessonId) => $query->where('schedule_lesson_id', $lessonId))
            ->when($request->integer('student_id'), fn ($query, int $studentId) => $query->where('student_id', $studentId))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20);

        return AttendanceResource::collection($attendance);
    }

    public function store(StoreAttendanceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->journalEntryService->ensureStudentBelongsToLessonGroup($data['schedule_lesson_id'], $data['student_id']);

        $attendance = Attendance::create($data);

        return (new AttendanceResource($attendance->load(['scheduleLesson', 'student'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Attendance $attendance): AttendanceResource
    {
        return new AttendanceResource($attendance->load(['scheduleLesson', 'student']));
    }

    public function update(UpdateAttendanceRequest $request, Attendance $attendance): AttendanceResource
    {
        $data = $request->mergedWithCurrentAttendance();
        $this->journalEntryService->ensureStudentBelongsToLessonGroup($data['schedule_lesson_id'], $data['student_id']);

        $attendance->update($request->validated());

        return new AttendanceResource($attendance->load(['scheduleLesson', 'student']));
    }

    public function destroy(Attendance $attendance): Response
    {
        $attendance->delete();

        return response()->noContent();
    }
}
