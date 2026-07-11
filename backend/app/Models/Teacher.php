<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    protected $fillable = [
        'person_id',
        'user_id',
        'last_name',
        'first_name',
        'middle_name',
        'phone',
        'email',
        'photo_path',
        'position',
        'department',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function curatedGroups(): HasMany
    {
        return $this->hasMany(Group::class, 'curator_id');
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class);
    }

    public function scheduleLessons(): HasMany
    {
        return $this->hasMany(ScheduleLesson::class);
    }

    public function teachingLoads(): HasMany
    {
        return $this->hasMany(TeachingLoad::class);
    }
}
