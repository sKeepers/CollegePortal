<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100', 'unique:subjects,code'],
            'department' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'teacher_ids' => ['sometimes', 'array'],
            'teacher_ids.*' => ['integer', 'exists:teachers,id'],
        ];
    }
}
