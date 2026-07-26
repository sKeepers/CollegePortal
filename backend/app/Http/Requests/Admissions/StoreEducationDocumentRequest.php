<?php

namespace App\Http\Requests\Admissions;

use Illuminate\Foundation\Http\FormRequest;

class StoreEducationDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'series' => ['nullable', 'string', 'max:20'],
            'number' => ['nullable', 'string', 'max:100'],
            'issue_date' => ['nullable', 'date'],
            'document_organization' => ['nullable', 'string', 'max:1000'],
            'country_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'country_name' => ['nullable', 'string', 'max:255'],
            'education_level_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'graduation_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'is_original' => ['nullable', 'boolean'],
            'original_received_at' => ['nullable', 'date'],
            'average_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'average_score_scale' => ['nullable', 'string', 'max:32'],
            'has_attachment' => ['nullable', 'boolean'],
            'is_primary' => ['nullable', 'boolean'],
            'verification_status' => ['nullable', 'string', 'max:40'],
            'verification_comment' => ['nullable', 'string', 'max:2000'],
            'fis_uid' => ['nullable', 'string', 'max:200'],
            'fis_document_type_id' => ['nullable', 'integer', 'min:1'],
            'fis_country_id' => ['nullable', 'integer', 'min:1'],
            'fis_region_id' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
