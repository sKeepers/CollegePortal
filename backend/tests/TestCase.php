<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function withApiAuth(?User $user = null): self
    {
        $token = Str::random(80);

        $user ??= $this->createApiUser($token);
        $user->forceFill([
            'api_token_hash' => Hash::make($token),
            'is_active' => true,
        ])->save();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    protected function createApiUser(?string $token = null, string $roleCode = 'admin'): User
    {
        $role = Role::query()->firstOrCreate(
            ['code' => $roleCode],
            ['name' => str($roleCode)->replace('_', ' ')->title()->toString(), 'description' => null]
        );

        return User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'api_token_hash' => $token ? Hash::make($token) : null,
        ]);
    }
}
