<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicantApplicationDocument extends Model
{
    public const STATUS_MISSING = 'missing';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    public const COMPLETE_STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_VERIFIED,
    ];

    protected $fillable = [
        'applicant_application_id',
        'document_type_id',
        'type',
        'title',
        'status',
        'is_received',
        'received_at',
        'received_by',
        'verified_at',
        'verified_by',
        'rejection_reason',
        'number',
        'comment',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'is_received' => 'boolean',
            'received_at' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    public function applicantApplication(): BelongsTo
    {
        return $this->belongsTo(ApplicantApplication::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'document_type_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ApplicantDocumentFile::class, 'applicant_application_document_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
