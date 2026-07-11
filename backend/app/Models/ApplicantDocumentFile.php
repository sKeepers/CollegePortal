<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantDocumentFile extends Model
{
    protected $fillable = [
        'applicant_application_document_id',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ApplicantApplicationDocument::class, 'applicant_application_document_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
