<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DormAbsenceResource extends JsonResource
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
            'night_of' => $this->night_of?->toDateString(),
            'left_at' => $this->left_at?->toISOString(),
            'returned_at' => $this->returned_at?->toISOString(),
            'note' => $this->note,
        ];
    }
}
