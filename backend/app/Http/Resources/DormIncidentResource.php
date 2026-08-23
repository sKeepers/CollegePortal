<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DormIncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'happened_at' => $this->happened_at?->toISOString(),
            'summary' => $this->summary,
            'description' => $this->description,
            'measures' => $this->measures,
            'dorm_room_id' => $this->dorm_room_id,
            'room' => $this->room?->number,
            'created_by' => $this->createdBy?->name,
            'participants' => $this->whenLoaded('participants', fn () => $this->participants->map(fn ($student) => [
                'id' => $student->id,
                'full_name' => trim(implode(' ', array_filter([$student->last_name, $student->first_name, $student->middle_name]))),
                'group' => $student->group?->name,
                'role' => $student->pivot?->role,
            ])->all()),
        ];
    }
}
