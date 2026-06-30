<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGradeRequest;
use App\Http\Requests\UpdateGradeRequest;
use App\Http\Resources\GradeResource;
use App\Models\Grade;
use App\Services\JournalEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class GradeController extends Controller
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $grades = Grade::query()
            ->with(['scheduleLesson', 'student'])
            ->when($request->integer('schedule_lesson_id'), fn ($query, int $lessonId) => $query->where('schedule_lesson_id', $lessonId))
            ->when($request->integer('student_id'), fn ($query, int $studentId) => $query->where('student_id', $studentId))
            ->when($request->string('grade_type')->toString(), fn ($query, string $gradeType) => $query->where('grade_type', $gradeType))
            ->latest()
            ->paginate(20);

        return GradeResource::collection($grades);
    }

    public function store(StoreGradeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->journalEntryService->ensureStudentBelongsToLessonGroup($data['schedule_lesson_id'], $data['student_id']);

        $grade = Grade::create($data);

        return (new GradeResource($grade->load(['scheduleLesson', 'student'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Grade $grade): GradeResource
    {
        return new GradeResource($grade->load(['scheduleLesson', 'student']));
    }

    public function update(UpdateGradeRequest $request, Grade $grade): GradeResource
    {
        $data = $request->mergedWithCurrentGrade();
        $this->journalEntryService->ensureStudentBelongsToLessonGroup($data['schedule_lesson_id'], $data['student_id']);

        $grade->update($request->validated());

        return new GradeResource($grade->load(['scheduleLesson', 'student']));
    }

    public function destroy(Grade $grade): Response
    {
        $grade->delete();

        return response()->noContent();
    }
}
