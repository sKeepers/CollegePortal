<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DormRoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Занятость приходит счётчиком отношения: считать её запросом на каждую
        // строку списка — это ровно тот «запрос на строку», которым уже болел
        // портал.
        $occupied = $this->current_placements_count;

        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'building' => $this->whenLoaded('building', fn () => [
                'id' => $this->building?->id,
                'name' => $this->building?->name,
            ]),
            'number' => $this->number,
            'floor' => $this->floor,
            'capacity' => $this->capacity,
            'occupied' => $occupied,
            'free' => $occupied === null ? null : max(0, $this->capacity - $occupied),
            'kind' => $this->kind,
            'is_active' => $this->is_active,
            'note' => $this->note,
        ];
    }
}
