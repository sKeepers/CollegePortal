<?php

namespace App\Http\Requests;

use DateTimeInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScheduleLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group_id' => ['sometimes', 'required', 'integer', 'exists:groups,id'],
            'teacher_id' => ['sometimes', 'required', 'integer', 'exists:teachers,id'],
            'subject_id' => ['sometimes', 'required', 'integer', 'exists:subjects,id'],
            'classroom_id' => ['sometimes', 'nullable', 'integer', 'exists:classrooms,id'],
            'lesson_date' => ['sometimes', 'required', 'date'],
            'starts_at' => ['sometimes', 'required', 'date_format:H:i'],
            'ends_at' => ['sometimes', 'required', 'date_format:H:i', 'after:starts_at'],
            'lesson_type' => ['sometimes', 'required', Rule::in(['lesson', 'lecture', 'practice', 'exam', 'consultation'])],
            'topic' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'group_id.required' => 'Выберите группу.',
            'teacher_id.required' => 'Выберите преподавателя.',
            'subject_id.required' => 'Выберите дисциплину.',
            'lesson_date.required' => 'Укажите дату занятия.',
            'starts_at.required' => 'Укажите время начала.',
            'ends_at.required' => 'Укажите время окончания.',
            'ends_at.after' => 'Время окончания должно быть позже времени начала.',
            'lesson_type.required' => 'Выберите тип занятия.',
        ];
    }

    public function mergedWithCurrentLesson(): array
    {
        $lesson = $this->route('schedule_lesson');

        return array_merge([
            'group_id' => $lesson->group_id,
            'teacher_id' => $lesson->teacher_id,
            'subject_id' => $lesson->subject_id,
            'classroom_id' => $lesson->classroom_id,
            'lesson_date' => $lesson->lesson_date?->toDateString(),
            'starts_at' => $this->formatTime($lesson->starts_at),
            'ends_at' => $this->formatTime($lesson->ends_at),
            'lesson_type' => $lesson->lesson_type,
            'topic' => $lesson->topic,
        ], $this->validated());
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
