<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DormPlacementResource;
use App\Models\DormPlacement;
use App\Models\DormRoom;
use App\Models\Student;
use App\Services\DormPlacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Заселение и переселение. Ведёт комендант.
 *
 * Переселение — отдельное действие, а не правка комнаты в существующей строке:
 * прежнее заселение закрывается, новое открывается, и история переселений
 * остаётся. Она нужна заместителю по воспитательной работе.
 */
class DormPlacementController extends Controller
{
    public function __construct(private readonly DormPlacementService $placements)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'dorm_room_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
            'open' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ], [
            'open.boolean' => 'Признак действующего заселения задаётся как «да» или «нет».',
        ]);

        $placements = DormPlacement::query()
            ->with(['room', 'student.group', 'createdBy'])
            ->when($filters['dorm_room_id'] ?? null, fn ($query, int $id) => $query->where('dorm_room_id', $id))
            ->when($filters['student_id'] ?? null, fn ($query, int $id) => $query->where('student_id', $id))
            ->when(array_key_exists('open', $filters) && $filters['open'] !== null, function ($query) use ($filters): void {
                $filters['open']
                    ? $query->whereNull('moved_out_at')
                    : $query->whereNotNull('moved_out_at');
            })
            ->orderByDesc('moved_in_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 100);

        return DormPlacementResource::collection($placements);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedPlacement($request);

        $placement = $this->placements->place(
            Student::query()->findOrFail($data['student_id']),
            DormRoom::query()->findOrFail($data['dorm_room_id']),
            $data['moved_in_at'],
            $data['basis'] ?? null,
            $data['note'] ?? null,
        );

        return (new DormPlacementResource($placement->load(['room', 'student.group'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function relocate(Request $request): DormPlacementResource
    {
        $data = $this->validatedPlacement($request);

        $placement = $this->placements->relocate(
            Student::query()->findOrFail($data['student_id']),
            DormRoom::query()->findOrFail($data['dorm_room_id']),
            $data['moved_in_at'],
            $data['basis'] ?? null,
            $data['note'] ?? null,
        );

        return new DormPlacementResource($placement->load(['room', 'student.group']));
    }

    public function moveOut(Request $request): DormPlacementResource
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'moved_out_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'moved_out_at.required' => 'Укажите дату выселения.',
        ]);

        $placement = $this->placements->moveOut(
            Student::query()->findOrFail($data['student_id']),
            $data['moved_out_at'],
            $data['note'] ?? null,
        );

        return new DormPlacementResource($placement->load(['room', 'student.group']));
    }

    private function validatedPlacement(Request $request): array
    {
        return $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'dorm_room_id' => ['required', 'integer', 'exists:dorm_rooms,id'],
            'moved_in_at' => ['required', 'date'],
            'basis' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'student_id.required' => 'Выберите студента.',
            'dorm_room_id.required' => 'Выберите комнату.',
            'moved_in_at.required' => 'Укажите дату заселения.',
        ]);
    }
}
