<?php

namespace App\Models\Admissions;

use App\Models\EducationProgram;
use App\Models\ReferenceItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foundation-модель заявления приемной комиссии.
 */
class AdmissionApplication extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_REGISTERED = 'registered';
    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $table = 'applicant_applications';

    protected $fillable = [
        'uuid',
        'applicant_id',
        'person_id',
        'admission_year',
        'application_number',
        'education_program_id',
        'last_name',
        'first_name',
        'middle_name',
        'birth_date',
        'phone',
        'email',
        'education_base',
        'status',
        'status_id',
        'source_id',
        'submitted_at',
        'registered_at',
        'comment',
        'metadata',
        'created_by',
        'updated_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'submitted_at' => 'date',
            'registered_at' => 'datetime',
            'metadata' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function educationProgram(): BelongsTo
    {
        return $this->belongsTo(EducationProgram::class);
    }

    public function statusItem(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'status_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'source_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeFoundation(Builder $query): Builder
    {
        return $query->whereNotNull('applicant_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function statusCode(): string
    {
        return $this->statusItem?->code ?: (string) $this->status;
    }

    public function isDraft(): bool
    {
        return $this->statusCode() === self::STATUS_DRAFT;
    }

    public function isRegistered(): bool
    {
        return $this->statusCode() === self::STATUS_REGISTERED;
    }
}
