<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'uid_raw',
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
            'issued_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** Журнал выдач этой карты: кому и когда она попадала. */
    public function issues(): HasMany
    {
        return $this->hasMany(RfidCardIssue::class);
    }

    /**
     * Открытая выдача — та, что ещё не закрыта возвратом.
     *
     * Она может быть только одна: вторая означала бы, что карта одновременно у
     * двоих.
     */
    public function currentIssue(): HasOne
    {
        return $this->hasOne(RfidCardIssue::class)->ofMany(
            ['issued_at' => 'MAX'],
            fn ($query) => $query->whereNull('returned_at'),
        );
    }

    /** Карта на руках у человека — по ней можно ходить. */
    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }
}
