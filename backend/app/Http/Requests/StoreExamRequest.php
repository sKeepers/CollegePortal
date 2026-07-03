<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year' => ['required', 'string', 'max:20'],
            'semester' => ['required', 'integer', 'min:1', 'max:12'],
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
            'exam_date' => ['required', 'date'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i', 'after_or_equal:starts_at'],
            'exam_type' => ['required', Rule::in(['exam', 'credit', 'differentiated_credit', 'gia'])],
            'status' => ['nullable', Rule::in(['draft', 'scheduled', 'completed', 'canceled'])],
            'topic' => ['nullable', 'string', 'max:255'],
        ];
    }
}
