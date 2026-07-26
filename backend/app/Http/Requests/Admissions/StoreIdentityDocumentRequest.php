<?php

namespace App\Http\Requests\Admissions;

use Illuminate\Foundation\Http\FormRequest;

class StoreIdentityDocumentRequest extends FormRequest
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
            'issued_by' => ['nullable', 'string', 'max:1000'],
            'subdivision_code' => ['nullable', 'string', 'max:32'],
            'release_country_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'release_country_name' => ['nullable', 'string', 'max:255'],
            'release_place' => ['nullable', 'string', 'max:1000'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'is_primary' => ['nullable', 'boolean'],
            'verification_status' => ['nullable', 'string', 'max:40'],
            'verification_comment' => ['nullable', 'string', 'max:2000'],
            'fis_uid' => ['nullable', 'string', 'max:200'],
            'fis_identity_document_type_id' => ['nullable', 'integer', 'min:1'],
            'fis_nationality_type_id' => ['nullable', 'integer', 'min:1'],
            'fis_release_country_id' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
