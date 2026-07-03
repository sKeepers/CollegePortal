<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSpecialtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('specialties', 'code')->ignore($this->route('specialty'))],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'education_level' => ['sometimes', 'required', 'string', 'max:255'],
            'qualification' => ['sometimes', 'nullable', 'string', 'max:255'],
            'normative_study_years' => ['sometimes', 'nullable', 'numeric', 'min:0.5', 'max:10'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
