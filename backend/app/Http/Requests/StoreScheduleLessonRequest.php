<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScheduleLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
            'lesson_date' => ['required', 'date'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'lesson_type' => ['required', Rule::in(['lesson', 'lecture', 'practice', 'exam', 'consultation'])],
            'topic' => ['nullable', 'string', 'max:255'],
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
}
