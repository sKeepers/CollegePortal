<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DormRoomResource;
use App\Models\DormRoom;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Комнаты общежития. Ведёт комендант.
 *
 * Комнату не удаляем: за ней стоит история заселений, и удаление увело бы её с
 * собой. Выведенная из обращения комната помечается неактивной — в списке она
 * остаётся, а для заселения закрыта.
 */
class DormRoomController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'building_id' => ['nullable', 'integer'],
            'only_free' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $rooms = DormRoom::query()
            ->with('building')
            ->withCount('currentPlacements')
            ->when($filters['building_id'] ?? null, fn ($query, int $id) => $query->where('building_id', $id))
            ->when(
                array_key_exists('is_active', $filters) && $filters['is_active'] !== null,
                fn ($query) => $query->where('is_active', $filters['is_active']),
            )
            ->orderBy('floor')
            ->orderBy('number')
            ->paginate($filters['per_page'] ?? 100);

        if ($filters['only_free'] ?? false) {
            // Свободное место — это разница между вместимостью и действующими
            // заселениями, а не отдельное поле, поэтому отбираем после счётчика.
            $rooms->setCollection(
                $rooms->getCollection()
                    ->filter(fn (DormRoom $room) => $room->capacity > $room->current_placements_count)
                    ->values(),
            );
        }

        return DormRoomResource::collection($rooms);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $room = DormRoom::create($data + ['is_active' => $data['is_active'] ?? true]);

        AuditLogService::log('dorm', 'room_created', $room, null, $room->only(['building_id', 'number', 'capacity']), $request);

        return (new DormRoomResource($room->loadCount('currentPlacements')->load('building')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, DormRoom $dormRoom): DormRoomResource
    {
        $data = $this->validated($request, $dormRoom->id);
        $old = $dormRoom->only(['number', 'floor', 'capacity', 'kind', 'is_active']);

        $dormRoom->update($data);

        AuditLogService::log('dorm', 'room_updated', $dormRoom, $old, $dormRoom->only(['number', 'floor', 'capacity', 'kind', 'is_active']), $request);

        return new DormRoomResource($dormRoom->loadCount('currentPlacements')->load('building'));
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $unique = Rule::unique('dorm_rooms', 'number')
            ->where(fn ($query) => $query->where('building_id', $request->integer('building_id')));

        if ($ignoreId !== null) {
            $unique = $unique->ignore($ignoreId);
        }

        return $request->validate([
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'number' => ['required', 'string', 'max:20', $unique],
            'floor' => ['nullable', 'integer', 'min:0', 'max:50'],
            'capacity' => ['required', 'integer', 'min:0', 'max:20'],
            'kind' => ['nullable', Rule::in(DormRoom::KINDS)],
            'is_active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'building_id.required' => 'Укажите корпус, в котором комната.',
            'number.required' => 'Укажите номер комнаты.',
            'number.unique' => 'В этом корпусе комната с таким номером уже заведена.',
            'capacity.required' => 'Укажите вместимость комнаты.',
        ]);
    }
}
