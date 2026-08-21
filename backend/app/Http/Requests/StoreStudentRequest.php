<?php

namespace App\Http\Requests;

use App\Rules\FreePersonalFileNumber;
use App\Rules\Snils;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'unique:students,user_id'],
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'snils' => ['nullable', 'string', 'max:32', app(Snils::class)],
            'address' => ['nullable', 'string', 'max:2000'],
            'passport_series' => ['nullable', 'string', 'max:20'],
            'passport_number' => ['nullable', 'string', 'max:100'],
            'passport_issue_date' => ['nullable', 'date'],
            'passport_issued_by' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(['active', 'academic_leave', 'graduated', 'expelled'])],
            'course' => ['nullable', 'integer', 'min:1', 'max:6'],
            'education_form' => ['nullable', 'string', 'max:80'],
            'funding_form' => ['nullable', 'string', 'max:80'],
            'enrollment_date' => ['nullable', 'date'],
            'enrollment_order_number' => ['nullable', 'string', 'max:100'],
            // Номер обязан быть свободен в пределах своей буквы: у каждой буквы
            // алфавита своя нумерация (учебная часть, 21.08.2026).
            'personal_file_number' => ['nullable', 'string', 'max:50',
                new FreePersonalFileNumber($this->string('last_name')->toString())],
            'enrollment_order_date' => ['nullable', 'date'],
        ];
    }
}
