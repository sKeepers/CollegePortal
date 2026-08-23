<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DormConductRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $student = $this->student;

        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'student' => $student === null ? null : [
                'id' => $student->id,
                'full_name' => trim(implode(' ', array_filter([$student->last_name, $student->first_name, $student->middle_name]))),
                'group' => $student->group?->name,
            ],
            'happened_on' => $this->happened_on?->toDateString(),
            'summary' => $this->summary,
            'description' => $this->description,
            'expires_on' => $this->expires_on?->toDateString(),
            // Запись не удаляется, а гаснет: погасшая уходит в историю и не
            // учитывается в действующих.
            'is_active' => $this->expires_on === null || $this->expires_on->isFuture(),
            'created_by' => $this->createdBy?->name,
            'created_at' => $this->created_at?->toISOString(),
            'amendments' => $this->whenLoaded('amendments', fn () => $this->amendments->map(fn ($item) => [
                'id' => $item->id,
                'summary' => $item->summary,
                'description' => $item->description,
                'created_by' => $item->createdBy?->name,
                'created_at' => $item->created_at?->toISOString(),
            ])->all()),
        ];
    }
}
