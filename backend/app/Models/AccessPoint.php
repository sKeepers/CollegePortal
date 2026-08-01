<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessPoint extends Model
{
    protected $fillable = ['name', 'location', 'direction_mode', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function devices(): HasMany
    {
        return $this->hasMany(AccessDevice::class);
    }
}
