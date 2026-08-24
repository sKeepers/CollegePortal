<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignDiplomaBlankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'graduate_id' => ['required', 'integer', 'exists:graduates,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
