<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSemesterGradesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'academic_year' => ['required', 'string', 'max:9'],
            'semester' => ['required', 'integer', 'min:1', 'max:12'],

            'grades' => ['required', 'array', 'min:1'],
            'grades.*.student_id' => ['required', 'integer', 'exists:students,id'],
            // Значение — строка, а не число: «зачтено» и «не аттестован» такие же
            // законные итоги, как «5». Пустое значение снимает оценку.
            'grades.*.value' => ['nullable', 'string', 'max:32'],
            'grades.*.score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'grades.*.comment' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'grades.required' => 'Ведомость пуста: передайте хотя бы одну строку.',
            'grades.*.student_id.exists' => 'Одного из студентов ведомости в портале нет.',
        ];
    }
}
