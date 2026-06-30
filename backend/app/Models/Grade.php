<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Grade extends Model
{
    protected $fillable = [
        'schedule_lesson_id',
        'student_id',
        'grade',
        'grade_type',
        'comment',
    ];

    public function scheduleLesson(): BelongsTo
    {
        return $this->belongsTo(ScheduleLesson::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
