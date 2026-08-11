<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Согласие человека получать событие в канал.
 *
 * Строки нет — согласия нет. По умолчанию не подписан никто: содержимое присылается как
 * есть, без нейтральных формулировок, и галочка — единственное, что отделяет уведомление
 * от рассылки персональных данных в чужой мессенджер.
 */
class NotificationSubscription extends Model
{
    protected $fillable = ['user_id', 'event', 'channel'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
