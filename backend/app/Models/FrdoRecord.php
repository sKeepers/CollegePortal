<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FrdoRecord extends Model
{
    protected $fillable = ['frdo_package_id', 'graduate_id', 'diploma_id', 'diploma_supplement_id', 'education_program_id', 'specialty_id', 'status', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function package(): BelongsTo { return $this->belongsTo(FrdoPackage::class, 'frdo_package_id'); }
    public function graduate(): BelongsTo { return $this->belongsTo(Graduate::class); }
    public function diploma(): BelongsTo { return $this->belongsTo(Diploma::class); }
    public function diplomaSupplement(): BelongsTo { return $this->belongsTo(DiplomaSupplement::class); }
    public function educationProgram(): BelongsTo { return $this->belongsTo(EducationProgram::class); }
    public function specialty(): BelongsTo { return $this->belongsTo(Specialty::class); }
    public function validationErrors(): HasMany { return $this->hasMany(FrdoValidationError::class); }
}
