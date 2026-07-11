<?php

namespace App\Http\Resources;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_year' => $this->academic_year,
            'semester' => $this->semester,
            'date' => $this->date?->toDateString(),
            'day_of_week' => $this->day_of_week,
            'week_type' => $this->week_type,
            'lesson_number' => $this->lesson_number,
            'starts_at' => $this->formatTime($this->starts_at),
            'ends_at' => $this->formatTime($this->ends_at),
            'group_id' => $this->group_id,
            'subject_id' => $this->subject_id,
            'teacher_id' => $this->teacher_id,
            'classroom_id' => $this->classroom_id,
            'teaching_load_item_id' => $this->teaching_load_item_id,
            'lesson_type_id' => $this->lesson_type_id,
            'status' => $this->status,
            'source' => $this->source,
            'is_replacement' => (bool) $this->is_replacement,
            'replaced_entry_id' => $this->replaced_entry_id,
            'comment' => $this->comment,
            'group' => new GroupResource($this->whenLoaded('group')),
            'teacher' => new TeacherResource($this->whenLoaded('teacher')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'classroom' => new ClassroomResource($this->whenLoaded('classroom')),
            'teaching_load_item' => new TeachingLoadItemResource($this->whenLoaded('teachingLoadItem')),
            'lesson_type' => new ReferenceItemResource($this->whenLoaded('lessonType')),
            'legacy_lesson_id' => $this->whenLoaded('legacyLesson', fn () => $this->legacyLesson?->id),
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
