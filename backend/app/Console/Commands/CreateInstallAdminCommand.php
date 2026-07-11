<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateInstallAdminCommand extends Command
{
    protected $signature = 'install:create-admin {--email=} {--password=} {--name=Администратор CollegePortal}';

    protected $description = 'Create or update the first administrator account for installation.';

    public function handle(): int
    {
        $email = (string) ($this->option('email') ?: '');
        $password = (string) ($this->option('password') ?: '');
        $name = (string) ($this->option('name') ?: 'Администратор CollegePortal');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Valid --email is required.');
            return self::FAILURE;
        }

        if (strlen($password) < 10) {
            $this->error('--password must contain at least 10 characters.');
            return self::FAILURE;
        }

        $role = Role::query()->where('code', 'admin')->first();
        if (! $role) {
            $this->error('Role admin not found. Run RoleSeeder first.');
            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'role_id' => $role->id,
                'name' => $name,
                'password' => Hash::make($password),
                'is_active' => true,
            ],
        );
        $user->roles()->syncWithoutDetaching([$role->id => ['is_primary' => true]]);

        $this->info("Admin account ready: {$email}");
        return self::SUCCESS;
    }
}
