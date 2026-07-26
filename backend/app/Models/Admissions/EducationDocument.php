<?php

namespace App\Models\Admissions;

use App\Models\ReferenceItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EducationDocument extends Model
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REPLACEMENT_REQUIRED = 'replacement_required';
    public const STATUS_ARCHIVED = 'archived';

    public const ACTIVE_STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_PENDING_REVIEW,
        self::STATUS_VERIFIED,
    ];

    protected $table = 'admission_education_documents';

    protected $fillable = [
        'uuid',
        'applicant_id',
        'document_type_id',
        'series',
        'number',
        'number_hash',
        'issue_date',
        'document_organization',
        'country_id',
        'country_name',
        'education_level_id',
        'graduation_year',
        'is_original',
        'original_received_at',
        'average_score',
        'average_score_scale',
        'has_attachment',
        'is_primary',
        'verification_status',
        'verification_comment',
        'fis_uid',
        'fis_document_type_id',
        'fis_country_id',
        'fis_region_id',
        'metadata',
        'created_by',
        'updated_by',
        'verified_by',
        'verified_at',
        'archived_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'graduation_year' => 'integer',
            'is_original' => 'boolean',
            'original_received_at' => 'date',
            'average_score' => 'decimal:2',
            'has_attachment' => 'boolean',
            'is_primary' => 'boolean',
            'metadata' => 'array',
            'verified_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'document_type_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'country_id');
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'education_level_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(AdmissionDocumentFile::class, 'education_document_id');
    }

    public function activeFiles(): HasMany
    {
        return $this->files()->whereNull('archived_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
