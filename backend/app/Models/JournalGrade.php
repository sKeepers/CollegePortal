<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalGrade extends Model
{
    protected $fillable = [
        'journal_lesson_id', 'student_id', 'grade_type_id', 'value', 'weight', 'comment', 'marked_by', 'marked_at',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'marked_at' => 'datetime',
        ];
    }

    public function journalLesson(): BelongsTo { return $this->belongsTo(JournalLesson::class); }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function gradeType(): BelongsTo { return $this->belongsTo(ReferenceItem::class, 'grade_type_id'); }
    public function markedBy(): BelongsTo { return $this->belongsTo(User::class, 'marked_by'); }
}
