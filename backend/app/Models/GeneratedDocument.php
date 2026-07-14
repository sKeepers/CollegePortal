<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeneratedDocument extends Model
{
    protected $fillable = [
        'uuid',
        'document_type_id',
        'document_template_id',
        'subject_type',
        'subject_id',
        'registration_number',
        'issue_date',
        'status',
        'output_docx_path',
        'output_pdf_path',
        'payload_snapshot',
        'payload_hash',
        'verification_token_hash',
        'verification_public_id',
        'generated_by',
        'issued_by',
        'issued_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'reprint_count',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'payload_snapshot' => 'array',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reprint_count' => 'integer',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class);
    }
}
