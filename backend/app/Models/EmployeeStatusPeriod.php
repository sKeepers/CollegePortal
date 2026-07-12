<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeStatusPeriod extends Model
{
    protected $fillable = ['employee_id', 'status', 'date_from', 'date_to', 'reason', 'document_number', 'document_date', 'comment', 'created_by'];
    protected function casts(): array { return ['date_from' => 'date', 'date_to' => 'date', 'document_date' => 'date']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
