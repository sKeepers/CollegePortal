<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEducationProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'specialty_id' => ['required', 'integer', 'exists:specialties,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('education_programs', 'name')
                    ->where('specialty_id', $this->input('specialty_id'))
                    ->where('year_start', $this->input('year_start'))
                    ->where('study_form', $this->input('study_form', 'Очная')),
            ],
            'year_start' => ['required', 'integer', 'min:2000', 'max:2100'],
            'study_form' => ['required', 'string', 'max:100'],
            'study_years' => ['nullable', 'numeric', 'min:0.5', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string'],
        ];
    }
}
