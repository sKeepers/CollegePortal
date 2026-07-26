<?php

namespace App\Models\Admissions;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationDocumentSet extends Model
{
    protected $table = 'admission_application_documents';

    protected $fillable = [
        'application_id',
        'identity_document_id',
        'education_document_id',
        'linked_by',
        'linked_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'metadata' => 'array',
        ];
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

    public function linker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
