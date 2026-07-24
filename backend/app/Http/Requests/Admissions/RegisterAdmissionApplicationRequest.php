<?php

namespace App\Http\Requests\Admissions;

use Illuminate\Foundation\Http\FormRequest;

class RegisterAdmissionApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'registered_at' => ['nullable', 'date'],
            'confirm_required_fields' => ['nullable', 'boolean'],
        ];
    }
}
