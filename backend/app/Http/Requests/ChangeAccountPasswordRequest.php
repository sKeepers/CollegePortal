<?php

namespace App\Http\Requests;

use App\Rules\SelfChosenPassword;
use Illuminate\Foundation\Http\FormRequest;

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
            // Требования вынесены в правило: тот же набор проверяет пароль, который
            // администратор задаёт в карточке пользователя. Выдаваемые порталом пароли
            // под него не подпадают намеренно — решение владельца от 11.08.2026.
            'password' => ['required', 'string', 'confirmed', 'max:255', new SelfChosenPassword],
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
