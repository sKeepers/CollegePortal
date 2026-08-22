<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DormAbsenceResource;
use App\Models\DormAbsence;
use App\Services\AuditLogService;
use App\Services\DormNightAbsenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Ночные отсутствия. Смотрят комендант и заместитель, считает портал.
 *
 * Признак означает «не входил до утра», а не «не ночевал»: проходная видит
 * только дверь. Так он и называется на экране — иначе список превращается в
 * обвинение, которого данные не выдерживают.
 */
class DormAbsenceController extends Controller
{
    public function __construct(private readonly DormNightAbsenceService $absences)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'student_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ], [
            'from.date' => 'Дата «с» не распознана.',
            'to.date' => 'Дата «по» не распознана.',
        ]);

        $rows = DormAbsence::query()
            ->with('student.group')
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('night_of', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('night_of', '<=', $to))
            ->when($filters['student_id'] ?? null, fn ($query, int $id) => $query->where('student_id', $id))
            ->orderByDesc('night_of')
            ->orderBy('student_id')
            ->paginate($filters['per_page'] ?? 200);

        return DormAbsenceResource::collection($rows);
    }

    /**
     * Пересчитать ночь.
     *
     * Ночь считается начисто, поэтому пересчёт после задним числом добавленной
     * отлучки убирает отсутствие, а не оставляет его висеть.
     */
    public function recalculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'night' => ['required', 'date'],
        ], [
            'night.required' => 'Укажите ночь, которую пересчитать.',
        ]);

        if ($this->absences->dormBuildingId() === null) {
            return response()->json([
                'message' => 'Не задан корпус общежития. Укажите его в настройках («Общежитие» → «Корпус общежития»), иначе считать не по чему: расчёт берёт проходы только его дверей.',
            ], 422);
        }

        $summary = $this->absences->recalculate($data['night']);

        AuditLogService::log('dorm', 'absences_recalculated', null, null, $summary + ['night' => $data['night']], $request);

        return response()->json(['data' => $summary]);
    }
}
