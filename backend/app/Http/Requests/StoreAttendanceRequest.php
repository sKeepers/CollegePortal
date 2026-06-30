<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schedule_lesson_id' => ['required', 'integer', 'exists:schedule_lessons,id'],
            'student_id' => [
                'required',
                'integer',
                'exists:students,id',
                Rule::unique('attendance', 'student_id')->where('schedule_lesson_id', $this->input('schedule_lesson_id')),
            ],
            'status' => ['required', Rule::in(['present', 'absent', 'late', 'excused'])],
            'comment' => ['nullable', 'string'],
        ];
    }
}
