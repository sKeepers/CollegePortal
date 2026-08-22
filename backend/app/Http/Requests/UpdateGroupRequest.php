<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('groups', 'name')->ignore($this->route('group'))],
            'specialty' => ['sometimes', 'required', 'string', 'max:255'],
            'education_program_id' => ['sometimes', 'nullable', 'integer', 'exists:education_programs,id'],
            'curriculum_id' => ['sometimes', 'nullable', 'integer', 'exists:curricula,id'],
            'year_start' => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            'curator_id' => ['sometimes', 'nullable', 'integer', 'exists:teachers,id'],
        ];
    }
}
