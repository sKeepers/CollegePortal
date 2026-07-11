<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumSubject extends Model
{
    protected $fillable = [
        'curriculum_id',
        'semester',
        'subject_id',
        'lecture_hours',
        'practice_hours',
        'laboratory_hours',
        'independent_hours',
        'total_hours',
        'control_type_id',
        'control_type',
        'sequence',
        'is_optional',
        'competencies',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'lecture_hours' => 'integer',
            'practice_hours' => 'integer',
            'laboratory_hours' => 'integer',
            'independent_hours' => 'integer',
            'total_hours' => 'integer',
            'sequence' => 'integer',
            'is_optional' => 'boolean',
            'competencies' => 'array',
        ];
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function controlType(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'control_type_id');
    }
}
