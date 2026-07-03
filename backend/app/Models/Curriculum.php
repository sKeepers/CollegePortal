<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Curriculum extends Model
{
    protected $fillable = ['education_program_id', 'name', 'year_start', 'status', 'description'];

    protected function casts(): array
    {
        return ['year_start' => 'integer'];
    }

    public function educationProgram(): BelongsTo
    {
        return $this->belongsTo(EducationProgram::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CurriculumItem::class)->orderBy('course')->orderBy('semester')->orderBy('sort_order')->orderBy('id');
    }
}
