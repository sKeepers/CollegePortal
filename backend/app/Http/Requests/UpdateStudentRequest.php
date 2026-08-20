<?php

namespace App\Http\Requests;

use App\Rules\Snils;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id', Rule::unique('students', 'user_id')->ignore($this->route('student'))],
            'group_id' => ['sometimes', 'required', 'integer', 'exists:groups,id'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'birth_date' => ['sometimes', 'nullable', 'date'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'snils' => ['sometimes', 'nullable', 'string', 'max:32', app(Snils::class)],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'passport_series' => ['sometimes', 'nullable', 'string', 'max:20'],
            'passport_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'passport_issue_date' => ['sometimes', 'nullable', 'date'],
            'passport_issued_by' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'academic_leave', 'graduated', 'expelled'])],
            'course' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:6'],
            'education_form' => ['sometimes', 'nullable', 'string', 'max:80'],
            'funding_form' => ['sometimes', 'nullable', 'string', 'max:80'],
            'enrollment_date' => ['sometimes', 'nullable', 'date'],
            'enrollment_order_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'personal_file_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'enrollment_order_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
