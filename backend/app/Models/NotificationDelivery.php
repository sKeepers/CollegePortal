<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка журнала отправок: кому, что за событие, когда, чем закончилось.
 *
 * **Текста сообщения здесь нет намеренно.** В нём персональные данные, а второй их
 * экземпляр порталу незачем: чтобы ответить на «мне не пришло», хватает события,
 * адресата, времени и исхода.
 */
class NotificationDelivery extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Подписки нет или диалог не начат — не ошибка, а причина не отправлять. */
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'user_id', 'event', 'channel', 'dedupe_key', 'status', 'failure_reason', 'attempts', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
