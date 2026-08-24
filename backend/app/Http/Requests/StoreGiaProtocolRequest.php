<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGiaProtocolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'max:50'],
            'protocol_date' => ['required', 'date'],
            'academic_year' => ['required', 'string', 'max:9'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'education_program_id' => ['nullable', 'integer', 'exists:education_programs,id'],

            // Председателя требуют и приказ, и ФРДО — без него протокол недействителен.
            'chairman' => ['required', 'string', 'max:255'],
            'chairman_position' => ['nullable', 'string', 'max:255'],
            'secretary' => ['nullable', 'string', 'max:255'],

            'members' => ['nullable', 'array'],
            'members.*.name' => ['required', 'string', 'max:255'],
            'members.*.position' => ['nullable', 'string', 'max:255'],

            'status' => ['nullable', Rule::in(['draft', 'approved'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'chairman.required' => 'Без председателя комиссии протокол недействителен: его требуют и приказ о выпуске, и ФРДО.',
            'number.required' => 'У протокола обязан быть номер: по нему на него ссылаются приказ и диплом.',
        ];
    }
}
