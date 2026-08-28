<?php

namespace App\Http\Requests;

use App\Services\Students\StudentCertificateService;
use Illuminate\Foundation\Http\FormRequest;

class IssueStudentCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // По умолчанию две: столько печатают студенту, и у каждой свой
            // номер. Поле есть на случай, когда нужна одна или третья взамен
            // испорченной при печати.
            'copies' => ['nullable', 'integer', 'min:1', 'max:'.StudentCertificateService::MAX_COPIES],
            'issued_on' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'copies.max' => 'За раз выдаётся не больше '.StudentCertificateService::MAX_COPIES.' справок.',
        ];
    }
}
