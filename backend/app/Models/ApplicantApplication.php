<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicantApplication extends Model
{
    public const RECORD_TYPE_LEGACY = 'legacy';
    public const RECORD_TYPE_FOUNDATION = 'foundation';

    protected $fillable = [
        'record_type',
        'foundation_version',
        'person_id',
        'external_source',
        'external_application_number',
        'external_status',
        'external_registered_at',
        'education_program_id',
        'competition_name',
        'last_name',
        'first_name',
        'middle_name',
        'birth_date',
        'phone',
        'email',
        'education_base',
        'education_form',
        'funding_form',
        'status',
        'submitted_at',
        'certificate_average_score',
        'achievement_score',
        'ranking_score',
        'documents_provided',
        'recommended_for_enrollment',
        'fis_raw_data',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'submitted_at' => 'date',
            'external_registered_at' => 'date',
            'certificate_average_score' => 'decimal:2',
            'achievement_score' => 'decimal:2',
            'ranking_score' => 'decimal:2',
            'documents_provided' => 'boolean',
            'recommended_for_enrollment' => 'boolean',
            'fis_raw_data' => 'array',
            'foundation_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ApplicantApplication $application): void {
            $application->record_type ??= self::RECORD_TYPE_LEGACY;
        });
    }

    public function scopeLegacy(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('record_type', self::RECORD_TYPE_LEGACY)
                ->orWhereNull('record_type');
        });
    }

    public function scopeFoundation(Builder $query): Builder
    {
        return $query->where('record_type', self::RECORD_TYPE_FOUNDATION);
    }

    public function isLegacyRecord(): bool
    {
        return $this->record_type === null || $this->record_type === self::RECORD_TYPE_LEGACY;
    }

    public function isFoundationRecord(): bool
    {
        return $this->record_type === self::RECORD_TYPE_FOUNDATION;
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function educationProgram(): BelongsTo
    {
        return $this->belongsTo(EducationProgram::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApplicantApplicationEvent::class)->latest();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicantApplicationDocument::class)->with(['documentType', 'files', 'receiver', 'verifier'])->orderBy('id');
    }
}
