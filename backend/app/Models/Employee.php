<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    public const UNAVAILABLE_STATUSES = ['vacation', 'sick_leave', 'maternity_leave', 'business_trip', 'suspended', 'dismissed'];

    protected $fillable = ['person_id', 'employee_number', 'status', 'employment_type', 'hired_at', 'dismissed_at', 'primary_department_id', 'primary_position_id', 'workload_rate', 'is_teacher', 'comment'];
    protected function casts(): array { return ['hired_at' => 'date', 'dismissed_at' => 'date', 'workload_rate' => 'decimal:2', 'is_teacher' => 'boolean']; }
    public function person(): BelongsTo { return $this->belongsTo(Person::class); }
    public function primaryDepartment(): BelongsTo { return $this->belongsTo(Department::class, 'primary_department_id'); }
    public function primaryPosition(): BelongsTo { return $this->belongsTo(Position::class, 'primary_position_id'); }
    public function assignments(): HasMany { return $this->hasMany(EmployeeAssignment::class); }
    public function statusPeriods(): HasMany { return $this->hasMany(EmployeeStatusPeriod::class); }
    public function teacher(): HasOne { return $this->hasOne(Teacher::class, 'person_id', 'person_id'); }
    public function activeStatusPeriod(?string $date = null): ?EmployeeStatusPeriod
    {
        $date ??= now()->toDateString();
        return $this->statusPeriods()->whereDate('date_from', '<=', $date)->where(fn ($q) => $q->whereNull('date_to')->orWhereDate('date_to', '>=', $date))->latest('date_from')->first();
    }
    public function statusOn(?string $date = null): string
    {
        $date ??= now()->toDateString();

        if ($period = $this->activeStatusPeriod($date)) {
            return $period->status;
        }

        $hired = $this->hired_at?->toDateString();
        $dismissed = $this->dismissed_at?->toDateString();
        if ($hired && $date < $hired) {
            return 'candidate';
        }
        if ($dismissed && $date >= $dismissed) {
            return 'dismissed';
        }

        return $hired ? 'active' : $this->status;
    }
    public function isActiveOn(?string $date = null): bool
    {
        $dismissed = $this->dismissed_at?->toDateString();
        return ! in_array($this->statusOn($date), self::UNAVAILABLE_STATUSES, true)
            && ($date === null ? $this->dismissed_at === null : (! $dismissed || $date < $dismissed));
    }
}
