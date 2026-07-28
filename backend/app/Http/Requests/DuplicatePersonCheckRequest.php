<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DuplicatePersonCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'last_name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before:tomorrow'],
            'snils' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'identity_document' => ['nullable', 'array'],
            'identity_document.series' => ['nullable', 'string', 'max:20'],
            'identity_document.number' => ['nullable', 'string', 'max:100'],
            'identity_series' => ['nullable', 'string', 'max:20'],
            'identity_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
