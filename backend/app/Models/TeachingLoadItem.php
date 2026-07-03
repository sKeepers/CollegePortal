<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeachingLoadItem extends Model
{
    protected $fillable = ['teaching_load_id', 'subject_id', 'group_id', 'semester', 'hours_total', 'load_type', 'sort_order'];

    protected function casts(): array
    {
        return ['semester' => 'integer', 'hours_total' => 'integer', 'sort_order' => 'integer'];
    }

    public function teachingLoad(): BelongsTo
    {
        return $this->belongsTo(TeachingLoad::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
