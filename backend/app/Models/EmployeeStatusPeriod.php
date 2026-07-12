<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeStatusPeriod extends Model
{
    public const TYPE_ABSENCE = ['vacation', 'sick_leave', 'maternity_leave', 'business_trip', 'suspended', 'dismissed'];
    public const PERIOD_STATUSES = ['planned', 'active', 'completed', 'cancelled'];

    protected $fillable = [
        'employee_id', 'status', 'period_status', 'date_from', 'date_to', 'reason', 'document_number', 'document_date',
        'comment', 'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'metadata',
    ];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'document_date' => 'date', 'cancelled_at' => 'datetime', 'metadata' => 'array'];
    }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function canceller(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function events(): HasMany { return $this->hasMany(HrEvent::class); }

    public function isOpenEnded(): bool { return $this->date_to === null; }
    public function isCancelled(): bool { return $this->period_status === 'cancelled'; }
}
