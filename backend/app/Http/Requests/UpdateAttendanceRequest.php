<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $attendance = $this->route('attendance');
        $scheduleLessonId = $this->input('schedule_lesson_id', $attendance?->schedule_lesson_id);

        return [
            'schedule_lesson_id' => ['sometimes', 'required', 'integer', 'exists:schedule_lessons,id'],
            'student_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:students,id',
                Rule::unique('attendance', 'student_id')->where('schedule_lesson_id', $scheduleLessonId)->ignore($attendance),
            ],
            'status' => ['sometimes', 'required', Rule::in(['present', 'absent', 'late', 'excused'])],
            'comment' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function mergedWithCurrentAttendance(): array
    {
        $attendance = $this->route('attendance');

        return array_merge([
            'schedule_lesson_id' => $attendance->schedule_lesson_id,
            'student_id' => $attendance->student_id,
            'status' => $attendance->status,
            'comment' => $attendance->comment,
        ], $this->validated());
    }
}
