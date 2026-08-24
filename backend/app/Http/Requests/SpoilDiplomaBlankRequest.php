<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Отметка о порче.
 *
 * Причина обязательна и не может быть отпиской в один знак: «испорчен» без
 * причины — это пропавший бланк с пометкой, а не отчёт.
 */
class SpoilDiplomaBlankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Укажите, чем именно испорчен бланк: по этой записи потом отчитываются.',
        ];
    }
}
