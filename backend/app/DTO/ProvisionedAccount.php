<?php

namespace App\DTO;

use App\Models\User;

readonly class ProvisionedAccount
{
    public function __construct(
        public User $user,
        public string $login,
        public string $password,
        public string $name,
        public string $role,
        // Название роли рядом с кодом, а не вместо него: код нужен журналу
        // действий, название — человеку. Одно значение в двух видах берётся из
        // одной строки справочника, а не собирается заново на фронтенде.
        public string $roleName = '',
    ) {
    }
}
