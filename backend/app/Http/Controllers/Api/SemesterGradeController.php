<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSemesterGradesRequest;
use App\Services\JournalLessonAccess;
use App\Services\SemesterGradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ведомость итоговых оценок за семестр.
 *
 * Смотреть её может тот, кто видит журнал группы; ставить — тот, у кого есть право и кто
 * вёл эту дисциплину. Разделено намеренно: куратору ведомость нужна, а ставить оценку по
 * чужому предмету он не должен.
 */
class SemesterGradeController extends Controller
{
    public function __construct(
        private readonly SemesterGradeService $service,
        private readonly JournalLessonAccess $access,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'academic_year' => ['required', 'string', 'max:9'],
            'semester' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        // Отказ обязан объяснять себя: пустой 403 в журнале уже стоил захода, когда
        // замещающий преподаватель получал отказ без единого слова.
        abort_unless(
            $this->access->canReadGroup($request->user(), (int) $data['group_id']),
            403,
            'Ведомость этой группы вам недоступна: вы не ведёте в ней занятий и не курируете её.',
        );

        return response()->json([
            'data' => $this->service->sheet(
                (int) $data['group_id'],
                (int) $data['subject_id'],
                (string) $data['academic_year'],
                (int) $data['semester'],
            ),
        ]);
    }

    public function store(StoreSemesterGradesRequest $request): JsonResponse
    {
        $data = $request->validated();

        abort_unless(
            $this->service->canGrade($request->user(), (int) $data['group_id'], (int) $data['subject_id']),
            403,
            'Итоговую оценку по дисциплине ставит преподаватель, который её вёл, или учебная часть.',
        );

        $result = $this->service->save(
            (int) $data['group_id'],
            (int) $data['subject_id'],
            (string) $data['academic_year'],
            (int) $data['semester'],
            $data['grades'],
            $request->user(),
        );

        return response()->json(['data' => $result]);
    }
}
