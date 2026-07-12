<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrEvent extends Model
{
    protected $fillable = ['event_type', 'employee_id', 'employee_status_period_id', 'schedule_entry_id', 'teacher_id', 'payload', 'severity', 'read_at', 'created_by'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'read_at' => 'datetime'];
    }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function period(): BelongsTo { return $this->belongsTo(EmployeeStatusPeriod::class, 'employee_status_period_id'); }
    public function scheduleEntry(): BelongsTo { return $this->belongsTo(ScheduleEntry::class); }
    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
