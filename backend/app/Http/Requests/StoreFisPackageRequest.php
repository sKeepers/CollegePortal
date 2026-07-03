<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFisPackageRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'package_type' => ['required', Rule::in(['admission', 'gia'])],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'education_program_id' => ['nullable', 'integer', 'exists:education_programs,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
