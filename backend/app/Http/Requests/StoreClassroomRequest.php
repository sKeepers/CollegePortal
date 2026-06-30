<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('classrooms', 'number')->where('building', $this->input('building')),
            ],
            'building' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'integer', 'min:0', 'max:50'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'type' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
