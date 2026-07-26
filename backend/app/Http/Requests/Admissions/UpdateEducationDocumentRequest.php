<?php

namespace App\Http\Requests\Admissions;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEducationDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type_id' => ['sometimes', 'nullable', 'integer', 'exists:reference_items,id'],
            'series' => ['sometimes', 'nullable', 'string', 'max:20'],
            'number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'issue_date' => ['sometimes', 'nullable', 'date'],
            'document_organization' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:reference_items,id'],
            'country_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'education_level_id' => ['sometimes', 'nullable', 'integer', 'exists:reference_items,id'],
            'graduation_year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'is_original' => ['sometimes', 'boolean'],
            'original_received_at' => ['sometimes', 'nullable', 'date'],
            'average_score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'average_score_scale' => ['sometimes', 'nullable', 'string', 'max:32'],
            'has_attachment' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
            'verification_status' => ['sometimes', 'string', 'max:40'],
            'verification_comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'fis_uid' => ['sometimes', 'nullable', 'string', 'max:200'],
            'fis_document_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'fis_country_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'fis_region_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
