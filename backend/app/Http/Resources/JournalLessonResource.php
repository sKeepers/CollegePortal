<?php

namespace App\Http\Resources;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalLessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'schedule_entry_id' => $this->schedule_entry_id,
            'legacy_schedule_lesson_id' => $this->legacy_schedule_lesson_id,
            'group_id' => $this->group_id,
            'subject_id' => $this->subject_id,
            'teacher_id' => $this->teacher_id,
            'lesson_date' => $this->lesson_date?->toDateString(),
            'starts_at' => $this->formatTime($this->starts_at),
            'ends_at' => $this->formatTime($this->ends_at),
            'lesson_type_id' => $this->lesson_type_id,
            'topic' => $this->topic,
            'homework' => $this->homework,
            'teacher_comment' => $this->teacher_comment,
            'status' => $this->status,
            'opened_at' => $this->opened_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'signed_at' => $this->signed_at?->toISOString(),
            'signed_by' => $this->signed_by,
            'group' => new GroupResource($this->whenLoaded('group')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'teacher' => new TeacherResource($this->whenLoaded('teacher')),
            'lesson_type' => new ReferenceItemResource($this->whenLoaded('lessonType')),
            'schedule_entry' => new ScheduleEntryResource($this->whenLoaded('scheduleEntry')),
            'attendance' => JournalAttendanceResource::collection($this->whenLoaded('attendance')),
            'grades' => JournalGradeResource::collection($this->whenLoaded('grades')),
            'files' => JournalLessonFileResource::collection($this->whenLoaded('files')),
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
