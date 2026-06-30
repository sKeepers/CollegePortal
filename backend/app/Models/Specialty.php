<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Specialty extends Model
{
    protected $fillable = [
        'code',
        'name',
        'education_level',
        'qualification',
        'normative_study_years',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'normative_study_years' => 'decimal:1',
        ];
    }

    public function educationPrograms(): HasMany
    {
        return $this->hasMany(EducationProgram::class);
    }
}
