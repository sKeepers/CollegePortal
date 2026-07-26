<?php

namespace App\Http\Requests\Admissions;

use Illuminate\Foundation\Http\FormRequest;

class UploadAdmissionDocumentFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:15360'],
            'category' => ['nullable', 'string', 'max:40'],
            'application_id' => ['nullable', 'integer', 'exists:applicant_applications,id'],
        ];
    }
}
