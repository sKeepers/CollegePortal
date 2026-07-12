<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalAttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'journal_lesson_id' => $this->journal_lesson_id,
            'student_id' => $this->student_id,
            'status' => $this->status,
            'minutes_late' => $this->minutes_late,
            'comment' => $this->comment,
            'source' => $this->source,
            'marked_by' => $this->marked_by,
            'marked_at' => $this->marked_at?->toISOString(),
            'student' => new StudentResource($this->whenLoaded('student')),
        ];
    }
}
