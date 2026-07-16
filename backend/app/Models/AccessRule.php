<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessRule extends Model
{
    protected $fillable = ['code', 'name', 'scope', 'conditions', 'active'];
    protected function casts(): array { return ['conditions' => 'array', 'active' => 'boolean']; }
}
