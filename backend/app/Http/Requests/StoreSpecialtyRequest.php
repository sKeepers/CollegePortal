<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSpecialtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'unique:specialties,code'],
            'name' => ['required', 'string', 'max:255'],
            'education_level' => ['required', 'string', 'max:255'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'normative_study_years' => ['nullable', 'numeric', 'min:0.5', 'max:10'],
            'description' => ['nullable', 'string'],
        ];
    }
}
