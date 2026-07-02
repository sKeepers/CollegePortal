<?php

namespace App\Http\Requests;

use App\Models\DigitalIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueDigitalIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_type' => ['required', Rule::in([DigitalIdentity::ENTITY_STUDENT, DigitalIdentity::ENTITY_TEACHER])],
            'entity_id' => ['required', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
