<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Оценка студента для его собственных экранов: карточка, кабинет, телефон.
 *
 * Источник один — `journal_grades`, то есть то, что преподаватель поставил в
 * журнале. До 16.08.2026 эти экраны читали старую таблицу `grades`, куда с
 * появления журнала не пишет ни один живой путь, и студент не видел ни одной
 * своей оценки.
 *
 * Поле называется `grade`, а не `value`: так его читают три экрана, и менять
 * имя ради красоты означало бы трогать их все ради ничего. Занятие приходит в
 * `lesson` — это занятие журнала, у него есть дата, дисциплина и преподаватель.
 */
class StudentGradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'journal_lesson_id' => $this->journal_lesson_id,
            'student_id' => $this->student_id,
            'grade' => $this->value,
            'weight' => $this->weight,
            'grade_type' => $this->whenLoaded('gradeType', fn () => $this->gradeType?->name),
            'comment' => $this->comment,
            'lesson' => new StudentLessonResource($this->whenLoaded('journalLesson')),
            'marked_at' => $this->marked_at?->toISOString(),
        ];
    }
}
