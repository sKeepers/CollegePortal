<?php

namespace App\Models;

use App\Services\Graduation\Exceptions\StrictReportingRecordIsNeverDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Один бланк строгой отчётности: диплом, приложение или дубликат.
 *
 * Бланк заводится приходом партии и живёт до конца: **удалить его нельзя**.
 * Испорченный при печати не исчезает, а остаётся с номером, причиной и датой —
 * по нему отчитываются, и «его тут не было» не ответ.
 */
class DiplomaBlank extends Model
{
    /** Диплом. */
    public const KIND_DIPLOMA = 'diploma';

    /** Диплом с отличием: у него своя серия и своя нумерация. */
    public const KIND_DIPLOMA_HONOURS = 'diploma_honours';

    /** Приложение к диплому. */
    public const KIND_SUPPLEMENT = 'supplement';

    /** Дубликат — выдаётся взамен утраченного, нумерация тоже своя. */
    public const KIND_DUPLICATE = 'duplicate';

    public const KINDS = [
        self::KIND_DIPLOMA,
        self::KIND_DIPLOMA_HONOURS,
        self::KIND_SUPPLEMENT,
        self::KIND_DUPLICATE,
    ];

    /** В наличии: лежит в сейфе, ни за кем не закреплён. */
    public const STATUS_STOCK = 'stock';

    /** Закреплён за выпускником, но ещё не выдан: номер занят, печатать можно. */
    public const STATUS_ASSIGNED = 'assigned';

    /** Выдан на руки. */
    public const STATUS_ISSUED = 'issued';

    /** Испорчен — при печати, при заполнении, как угодно. Из оборота выбыл, из книги нет. */
    public const STATUS_SPOILED = 'spoiled';

    /** Списан актом. Приходит только после порчи: списывают то, что испорчено. */
    public const STATUS_WRITTEN_OFF = 'written_off';

    public const STATUSES = [
        self::STATUS_STOCK,
        self::STATUS_ASSIGNED,
        self::STATUS_ISSUED,
        self::STATUS_SPOILED,
        self::STATUS_WRITTEN_OFF,
    ];

    protected $fillable = [
        'diploma_blank_batch_id',
        'kind',
        'series',
        'number',
        'status',
        'graduate_id',
        'diploma_id',
        'assigned_at',
        'issued_at',
        'spoiled_at',
        'written_off_at',
        'write_off_act',
        'reason',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'date',
            'issued_at' => 'date',
            'spoiled_at' => 'date',
            'written_off_at' => 'date',
        ];
    }

    /**
     * Удалить бланк нельзя ничем, что проходит через модель.
     *
     * Это не осторожность, а суть учёта: испорченный бланк, которого нет в
     * книге, — это бланк, о котором нечего сказать проверке. Запрет ловит и
     * `delete()` на записи, и `Model::destroy()`, и удаление через связь;
     * мимо него проходит только прямой запрос к таблице, и это осознанно —
     * миграция отката обязана уметь снести таблицу целиком.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $blank): void {
            throw new StrictReportingRecordIsNeverDeleted(
                'Бланк строгой отчётности не удаляется: испорченный отмечается как испорченный и списывается актом.'
            );
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DiplomaBlankBatch::class, 'diploma_blank_batch_id');
    }

    public function graduate(): BelongsTo
    {
        return $this->belongsTo(Graduate::class);
    }

    public function diploma(): BelongsTo
    {
        return $this->belongsTo(Diploma::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DiplomaBlankEvent::class)->orderBy('happened_at')->orderBy('id');
    }

    /** Обозначение бланка так, как его называет человек: «115924 0000123». */
    public function label(): string
    {
        return trim($this->series.' '.$this->number);
    }
}
