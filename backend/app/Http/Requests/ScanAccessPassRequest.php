<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScanAccessPassRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'access_point' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => 'Не передан токен цифрового пропуска.',
            'token.string' => 'Токен цифрового пропуска должен быть строкой.',
            'token.max' => 'Токен цифрового пропуска слишком длинный.',
        ];
    }
}
