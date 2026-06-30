<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $classroom = $this->route('classroom');
        $building = $this->input('building', $classroom?->building);

        return [
            'number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('classrooms', 'number')->where('building', $building)->ignore($classroom),
            ],
            'building' => ['sometimes', 'nullable', 'string', 'max:255'],
            'floor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:50'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
