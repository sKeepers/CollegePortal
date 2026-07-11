<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = [
        'name',
        'code',
        'module',
        'description',
        'system',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'system' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
