<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessPointRequest extends FormRequest
{
    public function rules(): array
    {
        $accessPoint = $this->route('access_point');

        return [
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('access_points', 'name')
                    ->where(fn ($query) => $query->where('building_id', $this->input('building_id')))
                    ->ignore($accessPoint),
            ],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('access_points', 'code')->ignore($accessPoint)],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function messages(): array
    {
        return [
            'building_id.required' => 'Выберите корпус, к которому относится точка прохода.',
            'building_id.exists' => 'Такого корпуса нет.',
            'name.required' => 'Укажите название точки прохода.',
            'name.unique' => 'В этом корпусе уже есть точка прохода с таким названием.',
            'code.unique' => 'Точка прохода с таким кодом уже есть.',
        ];
    }
}
