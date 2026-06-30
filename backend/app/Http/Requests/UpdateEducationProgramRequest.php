<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEducationProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $program = $this->route('education_program');
        $specialtyId = $this->input('specialty_id', $program?->specialty_id);
        $yearStart = $this->input('year_start', $program?->year_start);
        $studyForm = $this->input('study_form', $program?->study_form);

        return [
            'specialty_id' => ['sometimes', 'required', 'integer', 'exists:specialties,id'],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('education_programs', 'name')
                    ->where('specialty_id', $specialtyId)
                    ->where('year_start', $yearStart)
                    ->where('study_form', $studyForm)
                    ->ignore($program),
            ],
            'year_start' => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            'study_form' => ['sometimes', 'required', 'string', 'max:100'],
            'study_years' => ['sometimes', 'nullable', 'numeric', 'min:0.5', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
