<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGraduateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'student_id' => ['sometimes', 'required', 'integer', 'exists:students,id', Rule::unique('graduates', 'student_id')->ignore($this->route('graduate'))],
            'group_id' => ['sometimes', 'nullable', 'integer', 'exists:groups,id'],
            'education_program_id' => ['sometimes', 'nullable', 'integer', 'exists:education_programs,id'],
            'specialty_id' => ['sometimes', 'nullable', 'integer', 'exists:specialties,id'],
            'graduation_year' => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            'qualification' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::in(['draft', 'ready', 'issued', 'archived'])],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
