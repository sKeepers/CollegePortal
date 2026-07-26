<?php

namespace App\Models\Admissions;

use App\Models\Person;
use App\Models\ReferenceItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IdentityDocument extends Model
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

    protected $table = 'admission_identity_documents';

    protected $fillable = [
        'uuid',
        'previous_version_id',
        'version_number',
        'applicant_id',
        'person_id',
        'document_type_id',
        'series',
        'number',
        'number_hash',
        'issue_date',
        'issued_by',
        'subdivision_code',
        'release_country_id',
        'release_country_name',
        'release_place',
        'valid_until',
        'is_primary',
        'verification_status',
        'verification_comment',
        'fis_uid',
        'fis_identity_document_type_id',
        'fis_nationality_type_id',
        'fis_release_country_id',
        'metadata',
        'created_by',
        'updated_by',
        'verified_by',
        'verified_at',
        'archived_by',
        'replaced_by_document_id',
        'replaced_by',
        'replaced_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'valid_until' => 'date',
            'is_primary' => 'boolean',
            'metadata' => 'array',
            'verified_at' => 'datetime',
            'replaced_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->active()->whereNull('replaced_at');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'document_type_id');
    }

    public function releaseCountry(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'release_country_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(AdmissionDocumentFile::class, 'identity_document_id');
    }

    public function applicationDocumentSets(): HasMany
    {
        return $this->hasMany(ApplicationDocumentSet::class, 'identity_document_id');
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

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function replacedByDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_document_id');
    }
}
