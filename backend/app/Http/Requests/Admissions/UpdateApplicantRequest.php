<?php

namespace App\Http\Requests\Admissions;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'status_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'first_contact_at' => ['nullable', 'date'],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
