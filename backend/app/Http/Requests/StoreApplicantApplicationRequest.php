<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicantApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'education_program_id' => ['required', 'integer', 'exists:education_programs,id'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'education_base' => ['required', Rule::in(['after_9', 'after_11'])],
            'status' => ['required', Rule::in(['new', 'accepted', 'needs_clarification', 'rejected', 'enrolled'])],
            'submitted_at' => ['required', 'date'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
