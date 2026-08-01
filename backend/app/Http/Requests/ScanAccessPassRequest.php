<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScanAccessPassRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:1024'],
            'access_point_id' => ['nullable', 'integer', 'exists:access_points,id'],
            'access_point' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'integer', 'exists:access_devices,id'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_identifier' => ['nullable', 'string', 'max:120'],
            'device_type' => ['nullable', Rule::in(['mobile_camera', 'hid_scanner', 'manual'])],
            'direction' => ['nullable', Rule::in(['entry', 'exit', 'in', 'out'])],
            'request_id' => ['nullable', 'string', 'max:120'],
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
