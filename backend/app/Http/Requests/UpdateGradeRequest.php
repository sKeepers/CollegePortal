<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schedule_lesson_id' => ['sometimes', 'required', 'integer', 'exists:schedule_lessons,id'],
            'student_id' => ['sometimes', 'required', 'integer', 'exists:students,id'],
            'grade' => ['sometimes', 'required', 'string', 'max:20'],
            'grade_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'comment' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function mergedWithCurrentGrade(): array
    {
        $grade = $this->route('grade');

        return array_merge([
            'schedule_lesson_id' => $grade->schedule_lesson_id,
            'student_id' => $grade->student_id,
            'grade' => $grade->grade,
            'grade_type' => $grade->grade_type,
            'comment' => $grade->comment,
        ], $this->validated());
    }
}
