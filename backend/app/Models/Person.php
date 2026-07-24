<?php

namespace App\Models;

use App\Models\Admissions\Applicant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Person extends Model
{
    protected $fillable = [
        'uuid',
        'last_name',
        'first_name',
        'middle_name',
        'birth_date',
        'gender',
        'citizenship',
        'place_birth',
        'phone',
        'email',
        'address',
        'photo_path',
        'snils',
        'inn',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
        ];
    }

    public function students(): HasMany { return $this->hasMany(Student::class); }
    public function teachers(): HasMany { return $this->hasMany(Teacher::class); }
    public function applicants(): HasMany { return $this->hasMany(Applicant::class); }
    public function applicantApplications(): HasMany { return $this->hasMany(ApplicantApplication::class); }
    public function graduates(): HasMany { return $this->hasMany(Graduate::class); }
    public function users(): HasMany { return $this->hasMany(User::class); }
    public function digitalIdentities(): HasMany { return $this->hasMany(DigitalIdentity::class); }
    public function employees(): HasMany { return $this->hasMany(Employee::class); }

    public function primaryStudent(): HasOne { return $this->hasOne(Student::class)->latestOfMany(); }
    public function primaryTeacher(): HasOne { return $this->hasOne(Teacher::class)->latestOfMany(); }
    public function primaryEmployee(): HasOne { return $this->hasOne(Employee::class)->latestOfMany(); }
}
