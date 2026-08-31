<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role_id',
        'name',
        'email',
        'username',
        'password',
        'is_active',
        'must_change_password',
        'password_expires_at',
        'api_token_hash',
        'api_token_lookup_hash',
        'api_token_expires_at',
        'last_login_at',
        'person_type',
        'person_id',
        'viewing_as_user_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'api_token_hash',
        'api_token_lookup_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password_expires_at' => 'datetime',
            'api_token_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
            'person_id' => 'integer',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('is_primary')->withTimestamps();
    }

    /** Внешние способы входа: Telegram, MAX и что появится дальше. */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    public function hasPermission(string $permissionCode): bool
    {
        if ($this->hasRole('admin')) {
            return true;
        }

        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('code', $permissionCode)->where('active', true))
            ->exists()
            || $this->role()
                ->whereHas('permissions', fn ($query) => $query->where('code', $permissionCode)->where('active', true))
                ->exists();
    }

    public function permissionCodes(): array
    {
        $codes = collect();

        if ($this->relationLoaded('role') && $this->role?->relationLoaded('permissions')) {
            $codes = $codes->merge($this->role->permissions->where('active', true)->pluck('code'));
        }

        if ($this->relationLoaded('roles')) {
            $codes = $codes->merge($this->roles->flatMap(fn (Role $role) => $role->relationLoaded('permissions') ? $role->permissions->where('active', true)->pluck('code') : []));
        }

        return $codes->unique()->values()->all();
    }

    public function hasRole(string $roleCode): bool
    {
        return $this->roles()->where('code', $roleCode)->exists()
            || $this->role()->where('code', $roleCode)->exists();
    }
}
