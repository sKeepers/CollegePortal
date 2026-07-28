<?php

namespace App\Http\Requests\Admissions;

use Illuminate\Foundation\Http\FormRequest;

class StoreApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'person_id' => ['nullable', 'integer', 'exists:people,id'],
            'person' => ['nullable', 'array'],
            'person.last_name' => ['required_without:person_id', 'string', 'max:255'],
            'person.first_name' => ['required_without:person_id', 'string', 'max:255'],
            'person.middle_name' => ['nullable', 'string', 'max:255'],
            'person.birth_date' => ['nullable', 'date', 'before:tomorrow'],
            'person.gender' => ['nullable', 'string', 'max:32'],
            'person.citizenship' => ['nullable', 'string', 'max:100'],
            'person.place_birth' => ['nullable', 'string', 'max:255'],
            'person.phone' => ['nullable', 'string', 'max:50'],
            'person.email' => ['nullable', 'email', 'max:255'],
            'person.address' => ['nullable', 'string', 'max:2000'],
            'person.photo_path' => ['nullable', 'string', 'max:255'],
            'person.snils' => ['nullable', 'string', 'max:32'],
            'person.inn' => ['nullable', 'string', 'max:32'],
            'source_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'source_code' => ['nullable', 'string', 'max:100'],
            'status_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'status_code' => ['nullable', 'string', 'max:100'],
            'first_contact_at' => ['nullable', 'date'],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
