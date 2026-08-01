<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'category',
        'entity_type',
        'numbering_pattern',
        'requires_registration',
        'requires_qr',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_registration' => 'boolean',
            'requires_qr' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(DocumentTemplate::class);
    }
}
