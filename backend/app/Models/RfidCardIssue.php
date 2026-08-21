<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одна выдача карты: кому, когда, кем и чем закончилась.
 *
 * Это и есть журнал, который комендант раньше вёл в тетради. Открытая выдача —
 * та, у которой нет даты возврата; их у карты не может быть больше одной.
 *
 * Строки журнала не правятся и не удаляются: приём, потеря и замена закрывают
 * прежнюю строку и открывают новую. Иначе на вопрос «у кого карта была в марте»
 * снова будет нечем ответить.
 */
class RfidCardIssue extends Model
{
    /** Человек принёс карту и сдал. */
    public const REASON_RETURNED = 'returned';

    /** Карта утеряна. */
    public const REASON_LOST = 'lost';

    /** Карта испорчена и выведена из оборота. */
    public const REASON_DAMAGED = 'damaged';

    /** Карта заменена на другую — обмен, а не потеря. */
    public const REASON_REPLACED = 'replaced';

    /** Человек выбыл: отчислен, уволен. Карта не вернулась. */
    public const REASON_LEFT = 'left';

    public const REASONS = [
        self::REASON_RETURNED,
        self::REASON_LOST,
        self::REASON_DAMAGED,
        self::REASON_REPLACED,
        self::REASON_LEFT,
    ];

    protected $fillable = [
        'rfid_card_id',
        'person_id',
        'issued_at',
        'issued_by_user_id',
        'returned_at',
        'accepted_by_user_id',
        'close_reason',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(RfidCard::class, 'rfid_card_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /** Карта всё ещё на руках. */
    public function isOpen(): bool
    {
        return $this->returned_at === null;
    }

    public static function reasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            self::REASON_RETURNED => 'Сдана',
            self::REASON_LOST => 'Утеряна',
            self::REASON_DAMAGED => 'Испорчена',
            self::REASON_REPLACED => 'Заменена',
            self::REASON_LEFT => 'Человек выбыл',
            default => null,
        };
    }
}
