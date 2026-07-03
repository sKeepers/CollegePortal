<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCurriculumRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('curricula', 'code')->ignore($this->route('curriculum'))],
            'education_program_id' => ['sometimes', 'required', 'integer', 'exists:education_programs,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'year_start' => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            'status' => ['sometimes', 'nullable', Rule::in(['draft', 'active', 'archived'])],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
