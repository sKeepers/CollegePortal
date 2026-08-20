<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Заявка на удаление карточки.
 *
 * Удаляет только администратор. Роль, нашедшая ошибочно заведённую карточку,
 * оставляет заявку с причиной — без причины проверять нечего, а владелец
 * просил именно проверку, а не молчаливую пометку.
 */
class DeletionRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'subject_type', 'subject_id', 'subject_label', 'cascade', 'reason', 'status',
        'requested_by', 'reviewed_by', 'reviewed_at', 'review_comment',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'cascade' => 'array'];
    }

    /** Карточка может быть уже удалена — заявку это не отменяет. */
    public function subject(): MorphTo
    {
        return $this->morphTo('subject')->withTrashed();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
