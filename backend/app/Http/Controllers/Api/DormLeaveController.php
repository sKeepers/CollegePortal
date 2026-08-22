<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DormLeaveResource;
use App\Models\DormLeave;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Отлучка с ведома. Ведёт комендант общежития.
 *
 * Это не поблажка в отчёте, а часть расчёта: отлучка вычитается **до** того,
 * как ночное отсутствие станет отсутствием. Без неё правило «вышел и не
 * вернулся до утра» каждую пятницу собирало бы половину этажа.
 */
class DormLeaveController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'student_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ], [
            'from.date' => 'Дата «с» не распознана.',
            'to.date' => 'Дата «по» не распознана.',
        ]);

        $leaves = DormLeave::query()
            ->with(['student.group', 'createdBy'])
            ->when($filters['student_id'] ?? null, fn ($query, int $id) => $query->where('student_id', $id))
            // Пересечение с периодом, а не попадание внутрь: отлучка на две
            // недели обязана найтись по любому дню из этих двух недель.
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('ends_on', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('starts_on', '<=', $to))
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 100);

        return DormLeaveResource::collection($leaves);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'student_id.required' => 'Выберите студента.',
            'starts_on.required' => 'Укажите, с какого числа отлучка.',
            'ends_on.required' => 'Укажите, по какое число отлучка.',
            'ends_on.after_or_equal' => 'Отлучка не может кончаться раньше, чем началась.',
        ]);

        $leave = DormLeave::create($data + ['created_by_user_id' => Auth::id()]);

        AuditLogService::log('dorm', 'leave_created', $leave, null, $leave->only(['student_id', 'starts_on', 'ends_on']), $request);

        return (new DormLeaveResource($leave->load(['student.group', 'createdBy'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Request $request, DormLeave $dormLeave): JsonResponse
    {
        // Отлучку удаляют, когда её записали по ошибке. Пересчёт ночей после
        // этого вернёт отсутствия на место: расчёт идёт начисто.
        AuditLogService::log('dorm', 'leave_deleted', $dormLeave, $dormLeave->only(['student_id', 'starts_on', 'ends_on']), null, $request);

        $dormLeave->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
