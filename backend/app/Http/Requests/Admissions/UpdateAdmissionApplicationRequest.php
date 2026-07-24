<?php

namespace App\Http\Requests\Admissions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdmissionApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'admission_year' => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            'application_number' => ['nullable', 'string', 'max:80'],
            'education_program_id' => ['sometimes', 'required', 'integer', 'exists:education_programs,id'],
            'source_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'submitted_at' => ['sometimes', 'required', 'date'],
            'education_base' => ['nullable', Rule::in(['after_9', 'after_11', 'basic_general', 'secondary_general'])],
            'comment' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
