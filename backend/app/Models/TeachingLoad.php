<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeachingLoad extends Model
{
    protected $fillable = ['academic_year', 'teacher_id', 'status', 'description'];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TeachingLoadItem::class)->orderBy('semester')->orderBy('sort_order')->orderBy('id');
    }
}
