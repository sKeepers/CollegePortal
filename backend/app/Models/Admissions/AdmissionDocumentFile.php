<?php

namespace App\Models\Admissions;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionDocumentFile extends Model
{
    protected $fillable = [
        'uuid',
        'applicant_id',
        'application_id',
        'identity_document_id',
        'education_document_id',
        'category',
        'original_name',
        'generated_name',
        'storage_path',
        'mime_type',
        'extension',
        'size_bytes',
        'sha256',
        'uploaded_by',
        'uploaded_at',
        'archived_by',
        'archived_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
            'archived_at' => 'datetime',
            'metadata' => 'array',
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

    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'application_id');
    }

    public function identityDocument(): BelongsTo
    {
        return $this->belongsTo(IdentityDocument::class, 'identity_document_id');
    }

    public function educationDocument(): BelongsTo
    {
        return $this->belongsTo(EducationDocument::class, 'education_document_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
