<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FrdoPackage extends Model
{
    protected $fillable = ['name', 'graduation_year', 'education_program_id', 'status', 'validation_checked_at', 'exported_at', 'archived_at', 'note'];

    protected function casts(): array
    {
        return [
            'graduation_year' => 'integer',
            'validation_checked_at' => 'datetime',
            'exported_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function educationProgram(): BelongsTo { return $this->belongsTo(EducationProgram::class); }
    public function records(): HasMany { return $this->hasMany(FrdoRecord::class); }
    public function validationErrors(): HasMany { return $this->hasMany(FrdoValidationError::class); }
}
