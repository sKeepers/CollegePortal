<?php

namespace App\Http\Requests;

use App\Models\DiplomaBlank;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Приход партии бланков.
 *
 * Номера — строки, а не числа: у гознака они с ведущими нулями, и правило
 * `integer` съело бы их молча. Проверка «только цифры» стоит здесь, а
 * осмысленность диапазона — в службе, где видно и ширину, и объём.
 */
class ReceiveDiplomaBlankBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(DiplomaBlank::KINDS)],
            'series' => ['required', 'string', 'max:50'],
            'number_from' => ['required', 'string', 'max:50', 'regex:/^\d+$/'],
            'number_to' => ['required', 'string', 'max:50', 'regex:/^\d+$/'],
            'received_at' => ['required', 'date'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'number_from.regex' => 'Номер бланка состоит только из цифр; ведущие нули сохраняются.',
            'number_to.regex' => 'Номер бланка состоит только из цифр; ведущие нули сохраняются.',
        ];
    }
}
