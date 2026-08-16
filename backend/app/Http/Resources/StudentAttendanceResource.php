<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Отметка посещаемости студента для его собственных экранов.
 *
 * Источник — `journal_attendance`: то, что преподаватель отметил в журнале.
 * Строки с `source = roster` журнал создаёт сам при открытии занятия, до того
 * как преподаватель кого-либо отметил, и в списки студента они не попадают —
 * иначе «присутствовал» появлялось бы там, где занятие ещё не вели.
 */
class StudentAttendanceResource extends JsonResource
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
            'lesson' => new StudentLessonResource($this->whenLoaded('journalLesson')),
            'marked_at' => $this->marked_at?->toISOString(),
        ];
    }
}
