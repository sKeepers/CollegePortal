<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Занятие журнала в том объёме, который нужен студенту рядом с оценкой или
 * отметкой: когда, по чему и у кого. Ни статуса журнала, ни подписи, ни чужих
 * оценок здесь нет — студенту они не показываются.
 */
class StudentLessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lesson_date' => $this->lesson_date?->toDateString(),
            'starts_at' => $this->starts_at instanceof \DateTimeInterface ? $this->starts_at->format('H:i') : $this->starts_at,
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'teacher' => new TeacherResource($this->whenLoaded('teacher')),
            'group_id' => $this->group_id,
        ];
    }
}
