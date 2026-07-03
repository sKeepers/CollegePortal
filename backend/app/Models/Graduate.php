<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Graduate extends Model
{
    protected $fillable = ['student_id', 'group_id', 'education_program_id', 'specialty_id', 'graduation_year', 'qualification', 'photo_path', 'status', 'note'];

    protected function casts(): array
    {
        return ['graduation_year' => 'integer'];
    }

    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function group(): BelongsTo { return $this->belongsTo(Group::class); }
    public function educationProgram(): BelongsTo { return $this->belongsTo(EducationProgram::class); }
    public function specialty(): BelongsTo { return $this->belongsTo(Specialty::class); }
    public function diploma(): HasOne { return $this->hasOne(Diploma::class); }
}
