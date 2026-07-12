<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalLesson extends Model
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_OPENED = 'opened';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'schedule_entry_id', 'legacy_schedule_lesson_id', 'group_id', 'subject_id', 'teacher_id',
        'lesson_date', 'starts_at', 'ends_at', 'lesson_type_id', 'topic', 'homework', 'teacher_comment',
        'status', 'opened_at', 'completed_at', 'signed_at', 'signed_by',
    ];

    protected function casts(): array
    {
        return [
            'lesson_date' => 'date',
            'starts_at' => 'datetime:H:i',
            'ends_at' => 'datetime:H:i',
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function scheduleEntry(): BelongsTo { return $this->belongsTo(ScheduleEntry::class); }
    public function legacyScheduleLesson(): BelongsTo { return $this->belongsTo(ScheduleLesson::class, 'legacy_schedule_lesson_id'); }
    public function group(): BelongsTo { return $this->belongsTo(Group::class); }
    public function subject(): BelongsTo { return $this->belongsTo(Subject::class); }
    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }
    public function lessonType(): BelongsTo { return $this->belongsTo(ReferenceItem::class, 'lesson_type_id'); }
    public function signedBy(): BelongsTo { return $this->belongsTo(User::class, 'signed_by'); }
    public function attendance(): HasMany { return $this->hasMany(JournalAttendance::class); }
    public function grades(): HasMany { return $this->hasMany(JournalGrade::class); }
    public function files(): HasMany { return $this->hasMany(JournalLessonFile::class); }

    public function isSigned(): bool
    {
        return $this->status === self::STATUS_SIGNED || $this->signed_at !== null;
    }
}
