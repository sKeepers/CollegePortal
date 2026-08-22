<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DormPlacementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $student = $this->student;

        return [
            'id' => $this->id,
            'dorm_room_id' => $this->dorm_room_id,
            'room' => $this->whenLoaded('room', fn () => [
                'id' => $this->room?->id,
                'number' => $this->room?->number,
                'floor' => $this->room?->floor,
            ]),
            'student_id' => $this->student_id,
            'student' => $student === null ? null : [
                'id' => $student->id,
                'full_name' => trim(implode(' ', array_filter([
                    $student->last_name,
                    $student->first_name,
                    $student->middle_name,
                ]))),
                'group' => $student->group?->name,
            ],
            'moved_in_at' => $this->moved_in_at?->toDateString(),
            'moved_out_at' => $this->moved_out_at?->toDateString(),
            'is_open' => $this->moved_out_at === null,
            'basis' => $this->basis,
            'note' => $this->note,
            'created_by' => $this->createdBy?->name,
        ];
    }
}
