<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentStatusHistory extends Model
{
    protected $fillable = [
        'student_id',
        'status',
        'source',
        'order_number',
        'order_date',
        'notes',
        'import_job_id',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }
}
