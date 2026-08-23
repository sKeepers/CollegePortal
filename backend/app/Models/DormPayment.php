<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отметка об оплате проживания: «оплачено по такое-то число».
 *
 * Владелец считает оплату именно так — не помесячно, а до какой даты человек
 * закрыт. Отсюда `paid_through` вместо периода.
 *
 * В эту таблицу пишут двое: комендант руками и обмен с 1С. Правило разрешения
 * спора задано с самого начала, а не отложено: **строка из 1С побеждает ручную
 * отметку**, а ручная помечается замещённой и остаётся. Иначе первый же импорт
 * молча сотрёт работу коменданта, и никто не поймёт, куда она делась.
 */
class DormPayment extends Model
{
    /** Отметил комендант. */
    public const ORIGIN_MANUAL = 'manual';

    /** Пришло обменом из 1С. */
    public const ORIGIN_1C = '1c';

    public const ORIGINS = [self::ORIGIN_MANUAL, self::ORIGIN_1C];

    protected $fillable = [
        'student_id',
        'paid_through',
        'amount',
        'paid_at',
        'origin',
        'external_id',
        'superseded_by_id',
        'note',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'paid_through' => 'date',
            'paid_at' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Отметку заместили строкой из 1С: она осталась в истории, но не считается. */
    public function isSuperseded(): bool
    {
        return $this->superseded_by_id !== null;
    }
}
