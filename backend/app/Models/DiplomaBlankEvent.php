<?php

namespace App\Models;

use App\Services\Graduation\Exceptions\StrictReportingRecordIsNeverDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одно движение бланка: кто, когда, из какого состояния в какое и почему.
 *
 * Строки не удаляются. Отмена движения — это новое движение, а не стирание
 * прежнего: иначе на вопрос «когда бланк успел испортиться» ответить будет
 * нечем.
 */
class DiplomaBlankEvent extends Model
{
    public const ACTION_RECEIVED = 'received';

    public const ACTION_ASSIGNED = 'assigned';

    /** Закрепление снято: выпускник отказался, ошиблись номером. Бланк цел. */
    public const ACTION_RELEASED = 'released';

    public const ACTION_ISSUED = 'issued';

    public const ACTION_SPOILED = 'spoiled';

    public const ACTION_WRITTEN_OFF = 'written_off';

    public const ACTIONS = [
        self::ACTION_RECEIVED,
        self::ACTION_ASSIGNED,
        self::ACTION_RELEASED,
        self::ACTION_ISSUED,
        self::ACTION_SPOILED,
        self::ACTION_WRITTEN_OFF,
    ];

    /** Человеческие названия движений: их читает учебная часть, а не разработчик. */
    public const ACTION_LABELS = [
        self::ACTION_RECEIVED => 'принят партией',
        self::ACTION_ASSIGNED => 'закреплён за выпускником',
        self::ACTION_RELEASED => 'закрепление снято',
        self::ACTION_ISSUED => 'выдан',
        self::ACTION_SPOILED => 'испорчен',
        self::ACTION_WRITTEN_OFF => 'списан актом',
    ];

    protected $fillable = [
        'diploma_blank_id',
        'action',
        'from_status',
        'to_status',
        'graduate_id',
        'user_id',
        'act_number',
        'reason',
        'happened_at',
    ];

    protected function casts(): array
    {
        return ['happened_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $event): void {
            throw new StrictReportingRecordIsNeverDeleted(
                'Движение бланка не удаляется: отмена — это новая строка, а не стёртая старая.'
            );
        });
    }

    public function blank(): BelongsTo
    {
        return $this->belongsTo(DiplomaBlank::class, 'diploma_blank_id');
    }

    public function graduate(): BelongsTo
    {
        return $this->belongsTo(Graduate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
