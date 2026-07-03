<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EducationProgram extends Model
{
    protected $fillable = [
        'specialty_id',
        'name',
        'year_start',
        'study_form',
        'study_years',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'year_start' => 'integer',
            'study_years' => 'decimal:1',
            'is_active' => 'boolean',
        ];
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function applicantApplications(): HasMany
    {
        return $this->hasMany(ApplicantApplication::class);
    }

    public function curricula(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }
}
