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
    ) {
    }
}
