<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeachingLoadRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'academic_year' => ['required', 'string', 'max:20'],
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'curriculum_id' => ['nullable', 'integer', 'exists:curricula,id'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'archived'])],
            'description' => ['nullable', 'string'],
        ];
    }
}
