<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'schedule_lesson_id' => $this->schedule_lesson_id,
            'student_id' => $this->student_id,
            'grade' => $this->grade,
            'grade_type' => $this->grade_type,
            'comment' => $this->comment,
            'schedule_lesson' => new ScheduleLessonResource($this->whenLoaded('scheduleLesson')),
            'student' => new StudentResource($this->whenLoaded('student')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
