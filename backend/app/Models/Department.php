<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = ['code', 'name', 'parent_id', 'type', 'head_employee_id', 'is_active'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function parent(): BelongsTo { return $this->belongsTo(Department::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(Department::class, 'parent_id'); }
    public function headEmployee(): BelongsTo { return $this->belongsTo(Employee::class, 'head_employee_id'); }
    public function employees(): HasMany { return $this->hasMany(Employee::class, 'primary_department_id'); }
}
