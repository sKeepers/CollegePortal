<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumItem extends Model
{
    protected $fillable = ['curriculum_id', 'subject_id', 'course', 'semester', 'hours_total', 'control_form', 'sort_order'];

    protected function casts(): array
    {
        return ['course' => 'integer', 'semester' => 'integer', 'hours_total' => 'integer', 'sort_order' => 'integer'];
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
