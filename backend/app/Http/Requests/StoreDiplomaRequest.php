<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDiplomaRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'series' => ['nullable', 'string', 'max:50'],
            'number' => ['nullable', 'string', 'max:50'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'issue_date' => ['nullable', 'date'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'gia_decision' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['draft', 'ready', 'issued', 'revoked'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
