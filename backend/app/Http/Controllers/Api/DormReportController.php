<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DormPlacement;
use App\Models\DormRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Печатные списки и отчёты общежития.
 *
 * Комендант вывешивает список проживающих по этажам и носит его с собой, а лист
 * на одну комнату вешают на дверь. Отчёт заселённости спрашивают заместитель и
 * директор, и спрашивают раньше всего остального.
 *
 * Оба ответа собираются **одним проходом по данным**, а не запросом на строку:
 * проживающих может быть весь этаж, и «запрос на строку» этот портал уже ловил.
 */
class DormReportController extends Controller
{
    /** Список проживающих по этажам — для стены и для кармана. */
    public function residents(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'dorm_room_id' => ['nullable', 'integer'],
            'floor' => ['nullable', 'integer'],
        ]);

        $rooms = DormRoom::query()
            ->with(['currentPlacements.student.group', 'currentPlacements.student.person'])
            ->where('is_active', true)
            ->when($filters['dorm_room_id'] ?? null, fn ($query, int $id) => $query->whereKey($id))
            ->when($filters['floor'] ?? null, fn ($query, int $floor) => $query->where('floor', $floor))
            ->orderBy('floor')
            ->orderBy('number')
            ->get();

        $floors = $rooms->groupBy(fn (DormRoom $room) => $room->floor ?? 0)
            ->map(fn ($group, $floor) => [
                'floor' => $floor === 0 ? null : (int) $floor,
                'capacity' => (int) $group->sum('capacity'),
                'occupied' => (int) $group->sum(fn (DormRoom $room) => $room->currentPlacements->count()),
                'rooms' => $group->map(fn (DormRoom $room) => [
                    'id' => $room->id,
                    'number' => $room->number,
                    'capacity' => $room->capacity,
                    'occupied' => $room->currentPlacements->count(),
                    'people' => $room->currentPlacements->map(function (DormPlacement $placement): array {
                        $student = $placement->student;

                        return [
                            'student_id' => $placement->student_id,
                            'full_name' => trim(implode(' ', array_filter([
                                $student?->last_name,
                                $student?->first_name,
                                $student?->middle_name,
                            ]))),
                            'group' => $student?->group?->name,
                            // Курс не хранится, а считается из года набора —
                            // берём через готовый аксессор, а не выводим заново.
                            'course' => $student?->group?->course,
                            // Телефон студента, а если его нет — из карточки
                            // человека: коменданту звонить, а не выяснять, где
                            // номер записан.
                            'phone' => $student?->phone ?: $student?->person?->phone,
                            'moved_in_at' => $placement->moved_in_at?->toDateString(),
                        ];
                    })->values()->all(),
                ])->values()->all(),
            ])
            ->values();

        return response()->json(['data' => [
            'floors' => $floors->all(),
            'capacity' => (int) $rooms->sum('capacity'),
            'occupied' => (int) $rooms->sum(fn (DormRoom $room) => $room->currentPlacements->count()),
        ]]);
    }

    /** Заселённость за период: сколько занято и кто въехал и выехал. */
    public function occupancy(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ], [
            'from.required' => 'Укажите начало периода.',
            'to.required' => 'Укажите конец периода.',
            'to.after_or_equal' => 'Конец периода раньше начала.',
        ]);

        $from = Carbon::parse($filters['from'])->toDateString();
        $to = Carbon::parse($filters['to'])->toDateString();

        $rooms = DormRoom::query()
            ->where('is_active', true)
            ->withCount('currentPlacements')
            ->orderBy('floor')
            ->get();

        $byFloor = $rooms->groupBy(fn (DormRoom $room) => $room->floor ?? 0)
            ->map(fn ($group, $floor) => [
                'floor' => $floor === 0 ? null : (int) $floor,
                'rooms' => $group->count(),
                'capacity' => (int) $group->sum('capacity'),
                'occupied' => (int) $group->sum('current_placements_count'),
                'free' => (int) $group->sum(fn (DormRoom $room) => max(0, $room->capacity - $room->current_placements_count)),
            ])
            ->values();

        $movement = DormPlacement::query()
            ->with(['student.group', 'room'])
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('moved_in_at', [$from, $to])
                    ->orWhereBetween('moved_out_at', [$from, $to]);
            })
            ->orderBy('moved_in_at')
            ->get();

        $line = fn (DormPlacement $placement, string $kind, ?Carbon $date): array => [
            'kind' => $kind,
            'date' => $date?->toDateString(),
            'student_id' => $placement->student_id,
            'full_name' => trim(implode(' ', array_filter([
                $placement->student?->last_name,
                $placement->student?->first_name,
                $placement->student?->middle_name,
            ]))),
            'group' => $placement->student?->group?->name,
            'room' => $placement->room?->number,
        ];

        $moved = collect();

        foreach ($movement as $placement) {
            if ($placement->moved_in_at !== null && $placement->moved_in_at->toDateString() >= $from && $placement->moved_in_at->toDateString() <= $to) {
                $moved->push($line($placement, 'in', $placement->moved_in_at));
            }

            if ($placement->moved_out_at !== null && $placement->moved_out_at->toDateString() >= $from && $placement->moved_out_at->toDateString() <= $to) {
                $moved->push($line($placement, 'out', $placement->moved_out_at));
            }
        }

        $moved = $moved->sortBy('date')->values();

        $byDate = $moved->groupBy('date')
            ->map(fn ($rows, $date) => [
                'date' => $date,
                'in' => $rows->where('kind', 'in')->count(),
                'out' => $rows->where('kind', 'out')->count(),
            ])
            ->values();

        return response()->json(['data' => [
            'from' => $from,
            'to' => $to,
            'floors' => $byFloor->all(),
            'totals' => [
                'capacity' => (int) $rooms->sum('capacity'),
                'occupied' => (int) $rooms->sum('current_placements_count'),
                'free' => (int) $rooms->sum(fn (DormRoom $room) => max(0, $room->capacity - $room->current_placements_count)),
                'moved_in' => $moved->where('kind', 'in')->count(),
                'moved_out' => $moved->where('kind', 'out')->count(),
            ],
            'by_date' => $byDate->all(),
            'movement' => $moved->all(),
        ]]);
    }
}
