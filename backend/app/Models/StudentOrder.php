<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentOrder extends Model
{
    protected $fillable = [
        'student_id',
        'order_type',
        'order_number',
        'order_date',
        'effective_date',
        'from_group_id',
        'to_group_id',
        'from_course',
        'to_course',
        'status',
        'notes',
        'source',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'effective_date' => 'date',
            'from_course' => 'integer',
            'to_course' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
