<?php

namespace App\Http\Requests\Admissions;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProgramChoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'priority' => ['sometimes', 'required', 'integer', 'min:1', 'max:20'],
            'education_program_id' => ['sometimes', 'required', 'integer', 'exists:education_programs,id'],
            'education_form_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'funding_form_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'base_education_type_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'quota_type_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'status_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
