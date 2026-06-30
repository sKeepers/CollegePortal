<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schedule_lesson_id' => ['required', 'integer', 'exists:schedule_lessons,id'],
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'grade' => ['required', 'string', 'max:20'],
            'grade_type' => ['nullable', 'string', 'max:100'],
            'comment' => ['nullable', 'string'],
        ];
    }
}
