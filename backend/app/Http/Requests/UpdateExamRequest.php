<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year' => ['sometimes', 'required', 'string', 'max:20'],
            'semester' => ['sometimes', 'required', 'integer', 'min:1', 'max:12'],
            'group_id' => ['sometimes', 'required', 'integer', 'exists:groups,id'],
            'subject_id' => ['sometimes', 'required', 'integer', 'exists:subjects,id'],
            'teacher_id' => ['sometimes', 'required', 'integer', 'exists:teachers,id'],
            'classroom_id' => ['sometimes', 'nullable', 'integer', 'exists:classrooms,id'],
            'exam_date' => ['sometimes', 'required', 'date'],
            'starts_at' => ['sometimes', 'nullable', 'date_format:H:i'],
            'ends_at' => ['sometimes', 'nullable', 'date_format:H:i'],
            'exam_type' => ['sometimes', 'required', Rule::in(['exam', 'credit', 'differentiated_credit', 'gia'])],
            'status' => ['sometimes', 'nullable', Rule::in(['draft', 'scheduled', 'completed', 'canceled'])],
            'topic' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
