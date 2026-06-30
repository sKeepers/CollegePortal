<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    protected $fillable = [
        'name',
        'specialty',
        'education_program_id',
        'course',
        'year_start',
        'curator_id',
    ];

    protected function casts(): array
    {
        return [
            'course' => 'integer',
            'year_start' => 'integer',
        ];
    }

    public function curator(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'curator_id');
    }

    public function educationProgram(): BelongsTo
    {
        return $this->belongsTo(EducationProgram::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function scheduleLessons(): HasMany
    {
        return $this->hasMany(ScheduleLesson::class);
    }
}
