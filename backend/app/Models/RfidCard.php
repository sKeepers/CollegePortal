<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Физическая RFID-карта.
 *
 * Ведёт её комендант: заводит, выдаёт под роспись, принимает обратно, блокирует
 * потерянную. Карта привязана к **человеку**, а не к карточке студента или
 * сотрудника: человек бывает и тем и другим сразу, а карта у него одна.
 */
class RfidCard extends Model
{
    /** На складе, у коменданта. */
    public const STATUS_STOCK = 'stock';

    /** Выдана человеку. */
    public const STATUS_ISSUED = 'issued';

    /** Утеряна: у кого была — видно, но пользоваться ею нельзя. */
    public const STATUS_LOST = 'lost';

    /** Заблокирована: карта цела, но проход по ней закрыт. */
    public const STATUS_BLOCKED = 'blocked';

    /** Списана: карта из оборота выведена насовсем. */
    public const STATUS_WRITTEN_OFF = 'written_off';

    public const STATUSES = [
        self::STATUS_STOCK,
        self::STATUS_ISSUED,
        self::STATUS_LOST,
        self::STATUS_BLOCKED,
        self::STATUS_WRITTEN_OFF,
    ];

    protected $fillable = [
        'uid',
        'label',
        'person_id',
        'status',
        'issued_at',
        'returned_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'returned_at' => 'date',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** Карта на руках у человека — по ней можно ходить. */
    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }
}
