<?php

namespace App\Http\Requests;

use App\Rules\Inn;
use App\Rules\Snils;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before:tomorrow'],
            'gender' => ['nullable', 'string', 'max:32'],
            'citizenship' => ['nullable', 'string', 'max:100'],
            'place_birth' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'photo_path' => ['nullable', 'string', 'max:255'],
            'snils' => ['nullable', 'string', 'max:32', app(Snils::class)],
            'inn' => ['nullable', 'string', 'max:32', new Inn],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'archived'])],
        ];
    }
}
