<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeachingLoadRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'academic_year' => ['sometimes', 'required', 'string', 'max:20'],
            'teacher_id' => ['sometimes', 'nullable', 'integer', 'exists:teachers,id'],
            'curriculum_id' => ['sometimes', 'nullable', 'integer', 'exists:curricula,id'],
            'group_id' => ['sometimes', 'nullable', 'integer', 'exists:groups,id'],
            'status' => ['sometimes', 'nullable', Rule::in(['draft', 'active', 'archived'])],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
