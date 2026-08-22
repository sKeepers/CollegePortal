<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Комната в общежитии.
 *
 * Коек отдельной сущностью нет: вместимость — число, а занятость считается из
 * действующих заселений. Койко-место как объект понадобится, только если их
 * начнут различать по номерам.
 */
class DormRoom extends Model
{
    public const KIND_REGULAR = 'regular';
    public const KIND_ISOLATION = 'isolation';
    public const KIND_SERVICE = 'service';

    public const KINDS = [self::KIND_REGULAR, self::KIND_ISOLATION, self::KIND_SERVICE];

    protected $fillable = [
        'building_id',
        'number',
        'floor',
        'capacity',
        'kind',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'floor' => 'integer',
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function placements(): HasMany
    {
        return $this->hasMany(DormPlacement::class);
    }

    /** Заселения, которые сейчас действуют: выселения не было. */
    public function currentPlacements(): HasMany
    {
        return $this->placements()->whereNull('moved_out_at');
    }
}
