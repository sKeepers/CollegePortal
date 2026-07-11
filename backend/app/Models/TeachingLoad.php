<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeachingLoad extends Model
{
    protected $fillable = ['academic_year', 'teacher_id', 'curriculum_id', 'group_id', 'status', 'description', 'generated_at', 'generated_by'];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TeachingLoadItem::class)->orderBy('semester')->orderBy('sort_order')->orderBy('id');
    }
}
