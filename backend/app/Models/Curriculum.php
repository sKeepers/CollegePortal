<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Curriculum extends Model
{
    protected $fillable = ['code', 'education_program_id', 'name', 'qualification', 'year_start', 'status', 'description', 'competencies'];

    protected function casts(): array
    {
        return ['year_start' => 'integer', 'competencies' => 'array'];
    }

    public function educationProgram(): BelongsTo
    {
        return $this->belongsTo(EducationProgram::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CurriculumItem::class)->orderBy('course')->orderBy('semester')->orderBy('sort_order')->orderBy('id');
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(CurriculumSubject::class)->orderBy('semester')->orderBy('sequence')->orderBy('id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }
}
