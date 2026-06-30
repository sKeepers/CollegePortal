<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicantApplication extends Model
{
    protected $fillable = [
        'education_program_id',
        'last_name',
        'first_name',
        'middle_name',
        'birth_date',
        'phone',
        'email',
        'education_base',
        'status',
        'submitted_at',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'submitted_at' => 'date',
        ];
    }

    public function educationProgram(): BelongsTo
    {
        return $this->belongsTo(EducationProgram::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApplicantApplicationEvent::class)->latest();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicantApplicationDocument::class)->orderBy('id');
    }
}
