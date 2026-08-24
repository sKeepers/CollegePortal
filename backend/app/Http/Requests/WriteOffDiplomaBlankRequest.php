<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Списание.
 *
 * Номер акта обязателен: списание без акта — это бланк, исчезнувший по слову
 * того, кто нажал кнопку.
 */
class WriteOffDiplomaBlankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'act_number' => ['required', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'act_number.required' => 'Укажите номер акта списания.',
        ];
    }
}
