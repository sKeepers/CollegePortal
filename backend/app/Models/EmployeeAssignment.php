<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAssignment extends Model
{
    protected $fillable = ['employee_id', 'department_id', 'position_id', 'employment_type', 'rate', 'started_at', 'ended_at', 'is_primary', 'order_number', 'order_date', 'comment'];
    protected function casts(): array { return ['started_at' => 'date', 'ended_at' => 'date', 'order_date' => 'date', 'rate' => 'decimal:2', 'is_primary' => 'boolean']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function position(): BelongsTo { return $this->belongsTo(Position::class); }
}
