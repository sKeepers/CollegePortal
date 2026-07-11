<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255', 'unique:groups,name'],
            'specialty' => ['required', 'string', 'max:255'],
            'education_program_id' => ['nullable', 'integer', 'exists:education_programs,id'],
            'curriculum_id' => ['nullable', 'integer', 'exists:curricula,id'],
            'course' => ['required', 'integer', 'min:1', 'max:6'],
            'year_start' => ['required', 'integer', 'min:2000', 'max:2100'],
            'curator_id' => ['nullable', 'integer', 'exists:teachers,id'],
        ];
    }
}
