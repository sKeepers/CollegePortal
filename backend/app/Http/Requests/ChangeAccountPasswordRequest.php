<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangeAccountPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            // Восемь символов — требование только к тому, что человек задаёт сам.
            // Выдаваемые порталом пароли короче, и это отдельный вопрос политики,
            // который решает владелец; здесь мы просто не плодим новых коротких.
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }

    public function attributes(): array
    {
        return [
            'current_password' => 'текущий пароль',
            'password' => 'новый пароль',
        ];
    }
}
