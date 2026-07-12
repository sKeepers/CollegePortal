<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    protected $fillable = ['code', 'name', 'category', 'is_teaching_position', 'is_active'];
    protected function casts(): array { return ['is_teaching_position' => 'boolean', 'is_active' => 'boolean']; }
    public function employees(): HasMany { return $this->hasMany(Employee::class, 'primary_position_id'); }
}
