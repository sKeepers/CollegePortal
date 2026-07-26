<?php

namespace App\Http\Requests\Admissions;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIdentityDocumentRequest extends FormRequest
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
            'issued_by' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'subdivision_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'release_country_id' => ['sometimes', 'nullable', 'integer', 'exists:reference_items,id'],
            'release_country_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'release_place' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'is_primary' => ['sometimes', 'boolean'],
            'verification_status' => ['sometimes', 'string', 'max:40'],
            'verification_comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'fis_uid' => ['sometimes', 'nullable', 'string', 'max:200'],
            'fis_identity_document_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'fis_nationality_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'fis_release_country_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
