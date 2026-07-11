<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScheduleEntry extends Model
{
    protected $fillable = [
        'academic_year', 'semester', 'date', 'day_of_week', 'week_type', 'lesson_number', 'starts_at', 'ends_at',
        'group_id', 'subject_id', 'teacher_id', 'classroom_id', 'teaching_load_item_id', 'lesson_type_id',
        'status', 'source', 'is_replacement', 'replaced_entry_id', 'comment', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'date' => 'date',
            'day_of_week' => 'integer',
            'lesson_number' => 'integer',
            'starts_at' => 'datetime:H:i',
            'ends_at' => 'datetime:H:i',
            'is_replacement' => 'boolean',
        ];
    }

    public function group(): BelongsTo { return $this->belongsTo(Group::class); }
    public function subject(): BelongsTo { return $this->belongsTo(Subject::class); }
    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }
    public function classroom(): BelongsTo { return $this->belongsTo(Classroom::class); }
    public function teachingLoadItem(): BelongsTo { return $this->belongsTo(TeachingLoadItem::class); }
    public function lessonType(): BelongsTo { return $this->belongsTo(ReferenceItem::class, 'lesson_type_id'); }
    public function replacedEntry(): BelongsTo { return $this->belongsTo(self::class, 'replaced_entry_id'); }
    public function legacyLesson(): HasOne { return $this->hasOne(ScheduleLesson::class); }
}
