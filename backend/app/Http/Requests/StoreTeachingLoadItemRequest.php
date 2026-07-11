<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTeachingLoadItemRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'curriculum_subject_id' => ['nullable', 'integer', 'exists:curriculum_subjects,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
            'semester' => ['required', 'integer', 'min:1', 'max:12'],
            'hours_total' => ['required', 'integer', 'min:0', 'max:5000'],
            'planned_hours' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'assigned_hours' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'workload_type_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'load_type' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'source' => ['nullable', 'string', 'max:100'],
        ];
    }
}
