<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGraduateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id', 'unique:graduates,student_id'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'education_program_id' => ['nullable', 'integer', 'exists:education_programs,id'],
            'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
            'graduation_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['draft', 'ready', 'issued', 'archived'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
