<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DormLeaveResource extends JsonResource
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
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'reason' => $this->reason,
            'created_by' => $this->createdBy?->name,
        ];
    }
}
