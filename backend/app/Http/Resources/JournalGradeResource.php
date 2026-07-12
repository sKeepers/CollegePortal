<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalGradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'journal_lesson_id' => $this->journal_lesson_id,
            'student_id' => $this->student_id,
            'grade_type_id' => $this->grade_type_id,
            'value' => $this->value,
            'weight' => $this->weight,
            'comment' => $this->comment,
            'marked_by' => $this->marked_by,
            'marked_at' => $this->marked_at?->toISOString(),
            'student' => new StudentResource($this->whenLoaded('student')),
            'grade_type' => new ReferenceItemResource($this->whenLoaded('gradeType')),
        ];
    }
}
