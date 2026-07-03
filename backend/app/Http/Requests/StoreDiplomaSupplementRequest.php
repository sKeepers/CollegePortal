<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDiplomaSupplementRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'series' => ['nullable', 'string', 'max:50'],
            'number' => ['nullable', 'string', 'max:50'],
            'issue_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['draft', 'ready', 'issued', 'revoked'])],
            'subjects' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
