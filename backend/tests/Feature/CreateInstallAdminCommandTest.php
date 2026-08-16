<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateInstallAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_first_admin_user(): void
    {
        $role = Role::firstOrCreate(['code' => 'admin'], ['name' => 'Administrator']);

        $this->artisan('install:create-admin', [
            '--email' => 'first.admin@example.test',
            '--password' => 'demo1234567',
            '--name' => 'First Admin',
        ])->assertSuccessful();

        $user = User::where('email', 'first.admin@example.test')->firstOrFail();

        $this->assertSame('First Admin', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('demo1234567', $user->password));
        $this->assertTrue($user->roles()->whereKey($role->id)->exists());
    }

    public function test_it_rejects_short_admin_password(): void
    {
        Role::firstOrCreate(['code' => 'admin'], ['name' => 'Administrator']);

        $this->artisan('install:create-admin', [
            '--email' => 'first.admin@example.test',
            '--password' => 'short',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'first.admin@example.test']);
    }
}
