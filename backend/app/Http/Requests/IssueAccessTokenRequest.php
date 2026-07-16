<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueAccessTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'person_id' => ['nullable', 'integer', 'exists:people,id'],
            'entity_type' => ['nullable', Rule::in(['student', 'teacher'])],
            'entity_id' => ['nullable', 'integer'],
            'device_identifier' => ['nullable', 'string', 'max:120'],
        ];
    }
}
