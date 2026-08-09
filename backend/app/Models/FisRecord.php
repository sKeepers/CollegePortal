<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FisRecord extends Model
{
    protected $fillable = ['fis_package_id', 'applicant_application_id', 'exam_id', 'student_id', 'exam_result_id', 'graduate_id', 'education_program_id', 'specialty_id', 'status', 'payload'];

    protected function casts(): array { return ['payload' => 'array']; }

    public function package(): BelongsTo { return $this->belongsTo(FisPackage::class, 'fis_package_id'); }
    public function applicantApplication(): BelongsTo { return $this->belongsTo(ApplicantApplication::class); }
    public function exam(): BelongsTo { return $this->belongsTo(Exam::class); }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function examResult(): BelongsTo { return $this->belongsTo(ExamResult::class); }
    public function graduate(): BelongsTo { return $this->belongsTo(Graduate::class); }
    public function educationProgram(): BelongsTo { return $this->belongsTo(EducationProgram::class); }
    public function specialty(): BelongsTo { return $this->belongsTo(Specialty::class); }
    public function validationErrors(): HasMany { return $this->hasMany(FisValidationError::class); }
}
