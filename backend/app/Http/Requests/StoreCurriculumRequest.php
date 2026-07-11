<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCurriculumRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:100', 'unique:curricula,code'],
            'education_program_id' => ['required', 'integer', 'exists:education_programs,id'],
            'name' => ['required', 'string', 'max:255'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'year_start' => ['required', 'integer', 'min:2000', 'max:2100'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'archived'])],
            'description' => ['nullable', 'string'],
            'competencies' => ['nullable', 'array'],
        ];
    }
}
