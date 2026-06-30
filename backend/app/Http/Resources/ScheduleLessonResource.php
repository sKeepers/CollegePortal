<?php

namespace App\Http\Resources;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleLessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'teacher_id' => $this->teacher_id,
            'subject_id' => $this->subject_id,
            'classroom_id' => $this->classroom_id,
            'lesson_date' => $this->lesson_date?->toDateString(),
            'starts_at' => $this->formatTime($this->starts_at),
            'ends_at' => $this->formatTime($this->ends_at),
            'lesson_type' => $this->lesson_type,
            'topic' => $this->topic,
            'group' => new GroupResource($this->whenLoaded('group')),
            'teacher' => new TeacherResource($this->whenLoaded('teacher')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'classroom' => new ClassroomResource($this->whenLoaded('classroom')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function formatTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }
}
