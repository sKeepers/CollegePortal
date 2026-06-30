<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $table = 'attendance';

    protected $fillable = [
        'schedule_lesson_id',
        'student_id',
        'status',
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
