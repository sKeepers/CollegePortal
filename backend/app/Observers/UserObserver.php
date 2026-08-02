<?php

namespace App\Observers;

use App\Models\User;
use App\Services\DigitalPassRevocationService;

class UserObserver
{
    public function __construct(private readonly DigitalPassRevocationService $digitalPasses)
    {
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged('is_active') && ! $user->is_active) {
            $this->digitalPasses->revokeForUser($user);
        }
    }

    public function deleting(User $user): void
    {
        $this->digitalPasses->revokeForUser($user);
    }
}
