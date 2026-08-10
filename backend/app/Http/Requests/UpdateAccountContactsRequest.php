<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountContactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Только почта и телефон. ФИО, СНИЛС и дата рождения отсюда не правятся:
     * это документальные данные, их меняет кадровая или учебная часть.
     */
    public function rules(): array
    {
        return [
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
