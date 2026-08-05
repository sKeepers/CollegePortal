<?php

namespace App\Services;

use App\DTO\ProvisionedAccount;
use App\Models\Employee;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class AccountProvisioningService
{
    public function provision(Model $profile): ProvisionedAccount
    {
        $roleCode = match (true) {
            $profile instanceof Student => 'student',
            $profile instanceof Teacher => 'teacher',
            $profile instanceof Employee => 'employee',
            default => throw new LogicException('Неподдерживаемый профиль для создания учетной записи.'),
        };

        return DB::transaction(function () use ($profile, $roleCode): ProvisionedAccount {
            $profile->refresh();

            if ($profile->user_id ?? false) {
                throw new LogicException('Для профиля уже создана учетная запись.');
            }

            $person = $profile->person ?: $this->createPerson($profile);
            if (! $profile->person_id) {
                $profile->forceFill(['person_id' => $person->id])->save();
            }
            if (User::query()->where('person_id', $person->id)->exists()) {
                throw new LogicException('Для Person уже создана учетная запись.');
            }

            $username = $this->username($profile, $person);
            $email = $profile->email ?: $person->email ?: "{$username}@accounts.collegeportal.local";
            $role = Role::query()->where('code', $roleCode)->firstOrFail();
            $password = (string) random_int(10000, 99999);
            $name = trim("{$person->last_name} {$person->first_name} {$person->middle_name}");

            $user = User::create([
                'role_id' => $role->id,
                'name' => $name,
                'email' => $this->uniqueEmail($email),
                'username' => $username,
                'password' => $password,
                'is_active' => true,
                'person_type' => 'person',
                'person_id' => $person->id,
            ]);

            $user->roles()->attach($role->id, ['is_primary' => true]);
            if ($profile instanceof Student || $profile instanceof Teacher) {
                $profile->forceFill(['user_id' => $user->id])->save();
            }

            return new ProvisionedAccount($user, $username, $password, $name, $role->code);
        });
    }

    private function createPerson(Model $profile): Person
    {
        return Person::create([
            'last_name' => $profile->last_name,
            'first_name' => $profile->first_name,
            'middle_name' => $profile->middle_name,
            'birth_date' => $profile->birth_date,
            'phone' => $profile->phone,
            'email' => $profile->email,
            'status' => 'active',
        ]);
    }

    private function username(Model $profile, Person $person): string
    {
        $phone = $profile->phone ?: $person->phone;
        $email = $profile->email ?: $person->email;

        if ($phone) {
            return $this->uniqueUsername((string) $phone);
        }

        if ($email) {
            return $this->uniqueUsername((string) $email);
        }

        $initials = Str::ascii((string) $profile->first_name);
        $middleInitial = Str::ascii((string) $profile->middle_name);
        $base = Str::lower(Str::ascii((string) $profile->last_name).'.'.Str::substr($initials, 0, 1).Str::substr($middleInitial, 0, 1));
        $base = trim((string) preg_replace('/[^a-z0-9._-]+/', '', $base), '.');

        return $this->uniqueUsername($base ?: 'user');
    }

    private function uniqueUsername(string $base): string
    {
        $username = $base;
        $suffix = 2;
        while (User::query()->where('username', $username)->exists()) {
            $username = "{$base}{$suffix}";
            $suffix++;
        }

        return $username;
    }

    private function uniqueEmail(string $email): string
    {
        if (! User::query()->where('email', $email)->exists()) {
            return $email;
        }

        [$local, $domain] = array_pad(explode('@', $email, 2), 2, 'accounts.collegeportal.local');
        $suffix = 2;
        do {
            $candidate = "{$local}+{$suffix}@{$domain}";
            $suffix++;
        } while (User::query()->where('email', $candidate)->exists());

        return $candidate;
    }
}
