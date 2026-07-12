<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalAttendance extends Model
{
    protected $table = 'journal_attendance';

    public const STATUS_PRESENT = 'present';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_LATE = 'late';
    public const STATUS_EXCUSED = 'excused';
    public const STATUS_SICK = 'sick';
    public const STATUS_REMOTE = 'remote';

    protected $fillable = [
        'journal_lesson_id', 'student_id', 'status', 'minutes_late', 'comment', 'source', 'marked_by', 'marked_at',
    ];

    protected function casts(): array
    {
        return [
            'minutes_late' => 'integer',
            'marked_at' => 'datetime',
        ];
    }

    public function journalLesson(): BelongsTo { return $this->belongsTo(JournalLesson::class); }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function markedBy(): BelongsTo { return $this->belongsTo(User::class, 'marked_by'); }
}
