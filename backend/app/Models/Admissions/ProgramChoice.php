<?php

namespace App\Models\Admissions;

use App\Models\EducationProgram;
use App\Models\ReferenceItem;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foundation-модель выбранной образовательной программы заявления.
 */
class ProgramChoice extends Model
{
    protected $table = 'applicant_application_choices';

    protected $fillable = [
        'application_id',
        'priority',
        'specialty_id',
        'education_program_id',
        'education_form_id',
        'funding_form_id',
        'base_education_type_id',
        'quota_type_id',
        'status_id',
        'is_primary',
        'metadata',
        'created_by',
        'updated_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_primary' => 'boolean',
            'metadata' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'application_id');
    }

    public function educationProgram(): BelongsTo
    {
        return $this->belongsTo(EducationProgram::class);
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function educationForm(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'education_form_id');
    }

    public function fundingForm(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'funding_form_id');
    }

    public function baseEducationType(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'base_education_type_id');
    }

    public function quotaType(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'quota_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'status_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
