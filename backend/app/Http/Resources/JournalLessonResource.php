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
            'homework_due_at' => $this->homework_due_at?->toISOString(),
            'teacher_comment' => $this->teacher_comment,
            'status' => $this->status,
            'opened_at' => $this->opened_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'signed_at' => $this->signed_at?->toISOString(),
            'signed_by' => $this->signed_by,
            'reopened_at' => $this->reopened_at?->toISOString(),
            'reopened_by' => $this->reopened_by,
            'reopen_reason' => $this->reopen_reason,
            'edit_requests' => $this->whenLoaded('editRequests', fn () => $this->editRequests
                ->filter(fn ($request) => $request->requested_by === auth()->id() || request()->user()?->hasPermission('journal.reopen'))
                ->values()
                ->map(fn ($request) => [
                    'id' => $request->id,
                    'reason' => $request->reason,
                    'status' => $request->status,
                    'requested_by' => $request->requested_by,
                    'requested_by_name' => $request->requestedBy?->name,
                    'review_comment' => $request->review_comment,
                    'reviewed_at' => $request->reviewed_at?->toISOString(),
                ])),
            'group' => new GroupResource($this->whenLoaded('group')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'teacher' => new TeacherResource($this->whenLoaded('teacher')),
            'lesson_type' => new ReferenceItemResource($this->whenLoaded('lessonType')),
            'schedule_entry' => new ScheduleEntryResource($this->whenLoaded('scheduleEntry')),
            'attendance' => JournalAttendanceResource::collection($this->whenLoaded('attendance')),
            'grades' => JournalGradeResource::collection($this->whenLoaded('grades')),
            'files' => JournalLessonFileResource::collection($this->whenLoaded('files')),
            'metrics' => [
                'students' => $this->whenLoaded('attendance', fn () => $this->attendance->count()),
                'present' => $this->whenLoaded('attendance', fn () => $this->attendance->where('status', 'present')->count()),
                'absent' => $this->whenLoaded('attendance', fn () => $this->attendance->where('status', 'absent')->count()),
                'late' => $this->whenLoaded('attendance', fn () => $this->attendance->where('status', 'late')->count()),
                'grades' => $this->whenLoaded('grades', fn () => $this->grades->whereNotNull('value')->count()),
                'files' => $this->whenLoaded('files', fn () => $this->files->count()),
            ],
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
