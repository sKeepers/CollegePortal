<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBuildingRequest extends FormRequest
{
    public function rules(): array
    {
        $building = $this->route('building');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('buildings', 'name')->ignore($building)],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('buildings', 'code')->ignore($building)],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название корпуса.',
            'name.unique' => 'Корпус с таким названием уже есть.',
            'code.unique' => 'Корпус с таким кодом уже есть.',
        ];
    }
}
