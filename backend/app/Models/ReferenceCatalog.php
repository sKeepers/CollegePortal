<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferenceCatalog extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReferenceItem::class, 'catalog_id');
    }
}
