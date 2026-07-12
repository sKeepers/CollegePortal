<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLessonFile extends Model
{
    protected $fillable = [
        'journal_lesson_id', 'original_name', 'path', 'mime_type', 'size_bytes', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function journalLesson(): BelongsTo { return $this->belongsTo(JournalLesson::class); }
    public function uploadedBy(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
