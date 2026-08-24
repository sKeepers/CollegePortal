<?php

namespace App\Models;

use App\Services\Graduation\Exceptions\StrictReportingRecordIsNeverDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Партия бланков: откуда взялись эти номера.
 *
 * Приходит накладной, диапазоном номеров и датой. Удалить партию нельзя — она
 * объясняет происхождение каждого бланка, а бланки не удаляются тем более.
 */
class DiplomaBlankBatch extends Model
{
    protected $fillable = [
        'kind',
        'series',
        'number_from',
        'number_to',
        'quantity',
        'received_at',
        'supplier',
        'invoice_number',
        'received_by_user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'quantity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $batch): void {
            throw new StrictReportingRecordIsNeverDeleted(
                'Партия бланков строгой отчётности не удаляется: она объясняет, откуда взялись номера.'
            );
        });
    }

    public function blanks(): HasMany
    {
        return $this->hasMany(DiplomaBlank::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
