<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleTemplateEntry extends Model
{
    protected $fillable = ['schedule_template_id', 'day_of_week', 'week_type', 'lesson_number', 'starts_at', 'ends_at', 'subject_id', 'teacher_id', 'classroom_id', 'teaching_load_item_id', 'lesson_type_id', 'comment'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer', 'lesson_number' => 'integer', 'starts_at' => 'datetime:H:i', 'ends_at' => 'datetime:H:i'];
    }

    public function template(): BelongsTo { return $this->belongsTo(ScheduleTemplate::class, 'schedule_template_id'); }
    public function subject(): BelongsTo { return $this->belongsTo(Subject::class); }
    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }
    public function classroom(): BelongsTo { return $this->belongsTo(Classroom::class); }
    public function teachingLoadItem(): BelongsTo { return $this->belongsTo(TeachingLoadItem::class); }
    public function lessonType(): BelongsTo { return $this->belongsTo(ReferenceItem::class, 'lesson_type_id'); }
}
