<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferenceItem extends Model
{
    protected $fillable = [
        'catalog_id',
        'code',
        'name',
        'sort_order',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(ReferenceCatalog::class, 'catalog_id');
    }

    public function isSystem(): bool
    {
        return (bool) ($this->metadata['is_system'] ?? false);
    }
}
