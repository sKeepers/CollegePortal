<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одноразовый код, которым вошедший человек предъявляет себя боту.
 *
 * Живёт минуты и гаснет после первого применения: иначе забытый вчерашний код остался бы
 * рабочим ключом к чужим уведомлениям.
 */
class NotificationLinkCode extends Model
{
    protected $fillable = ['user_id', 'channel', 'code', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
