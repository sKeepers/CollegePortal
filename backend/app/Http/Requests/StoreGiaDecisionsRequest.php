<?php

namespace App\Http\Requests;

use App\Models\GiaProtocolDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGiaDecisionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decisions' => ['required', 'array', 'min:1'],
            'decisions.*.student_id' => ['required', 'integer', 'exists:students,id'],
            // Пустое значение снимает решение, поэтому `nullable`, а не список из трёх.
            'decisions.*.result' => ['nullable', Rule::in([
                '',
                GiaProtocolDecision::RESULT_PASSED,
                GiaProtocolDecision::RESULT_FAILED,
                GiaProtocolDecision::RESULT_ABSENT,
            ])],
            'decisions.*.mark' => ['nullable', 'string', 'max:32'],
            'decisions.*.qualification' => ['nullable', 'string', 'max:255'],
            'decisions.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'decisions.required' => 'Протокол без решений не бывает: передайте хотя бы одну строку.',
        ];
    }
}
