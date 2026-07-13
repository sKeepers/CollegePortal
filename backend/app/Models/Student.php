<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    protected $fillable = [
        'person_id',
        'user_id',
        'group_id',
        'course',
        'last_name',
        'first_name',
        'middle_name',
        'birth_date',
        'phone',
        'email',
        'photo_path',
        'status',
        'enrollment_date',
        'education_form',
        'funding_form',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'enrollment_date' => 'date',
            'course' => 'integer',
            'archived_at' => 'datetime',
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

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(StudentStatusHistory::class);
    }
}
