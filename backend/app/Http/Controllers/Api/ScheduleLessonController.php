<?php

namespace App\Http\Controllers\Api;

use App\Models\LessonTime;
use App\DTO\ScheduleLessonData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduleLessonRequest;
use App\Http\Requests\UpdateScheduleLessonRequest;
use App\Http\Resources\ScheduleLessonResource;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Import\ScheduleImportHandler;
use App\Services\ScheduleLessonService;
use App\Support\Csv\CsvExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * Выгрузка расписания. Её не было вовсе: импорт есть, обратной стороны нет.
     *
     * Колонки берутся у обработчика импорта, а не пишутся заново, — критерий
     * простой: выгруженный файл должен грузиться обратно тем же «Универсальным
     * импортом» без единой правки. Дата и время выводятся в том же написании,
     * которое принимает импорт.
     *
     * Фильтры те же, что у списка: выгружают обычно то, что перед этим
     * отобрали на экране, а не всё расписание за всё время.
     */
    public function export(Request $request, ScheduleImportHandler $handler): StreamedResponse
    {
        $lessons = ScheduleLesson::query()
            ->with(['group', 'teacher', 'subject', 'classroom'])
            ->when($request->integer('group_id'), fn ($query, int $id) => $query->where('group_id', $id))
            ->when($request->integer('teacher_id'), fn ($query, int $id) => $query->where('teacher_id', $id))
            ->when($request->integer('subject_id'), fn ($query, int $id) => $query->where('subject_id', $id))
            ->when($request->integer('classroom_id'), fn ($query, int $id) => $query->where('classroom_id', $id))
            ->when($request->query('date'), fn ($query, string $date) => $query->whereDate('lesson_date', $date))
            ->when($request->query('date_from'), fn ($query, string $date) => $query->whereDate('lesson_date', '>=', $date))
            ->when($request->query('date_to'), fn ($query, string $date) => $query->whereDate('lesson_date', '<=', $date))
            ->tap(fn ($query) => $this->applyScope($query, $request))
            ->orderBy('lesson_date')
            ->orderBy('starts_at')
            ->lazy();

        $bells = LessonTime::query()->where('is_active', true)->get()
            ->mapWithKeys(fn (LessonTime $bell): array => [$bell->startsAtShort() => $bell->lesson_number])
            ->all();

        return CsvExport::download('schedule-'.now()->format('Ymd-His').'.csv', $handler->templateHeaders(), function (callable $row) use ($lessons, $bells): void {
            foreach ($lessons as $lesson) {
                $row([
                    $lesson->lesson_date?->format('d.m.Y'),
                    // Номер пары выводится из времени начала: своей колонки у
                    // legacy-записи нет, а выгрузка обязана давать файл, который
                    // загрузится обратно без правки — вместе с новой колонкой.
                    $bells[$lesson->starts_at?->format('H:i')] ?? null,
                    // Время приведено к Carbon кастом модели: строкой оно даёт
                    // полную дату со временем, и в колонке «Время начала»
                    // оказывалось «2026-».
                    $lesson->starts_at?->format('H:i'),
                    $lesson->ends_at?->format('H:i'),
                    $lesson->group?->name,
                    trim(implode(' ', array_filter([$lesson->teacher?->last_name, $lesson->teacher?->first_name, $lesson->teacher?->middle_name]))),
                    $lesson->subject?->name,
                    $lesson->subject?->code,
                    $lesson->classroom?->number,
                    $lesson->classroom?->building,
                    $lesson->lesson_type,
                    $lesson->topic,
                ]);
            }
        });
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
