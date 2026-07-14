<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTemplate extends Model
{
    protected $fillable = [
        'document_type_id',
        'name',
        'version',
        'status',
        'source_format',
        'template_path',
        'output_formats',
        'variables_schema',
        'effective_from',
        'effective_to',
        'created_by',
        'published_by',
        'published_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'output_formats' => 'array',
            'variables_schema' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }
}
