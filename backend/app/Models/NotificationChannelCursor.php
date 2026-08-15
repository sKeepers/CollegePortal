<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Указатель прочитанного в очереди обновлений бота.
 *
 * Очередь у бота одна и общая, поэтому читать её должен ровно один процесс: два
 * читателя растащат события друг у друга, и часть привязок потеряется.
 */
class NotificationChannelCursor extends Model
{
    protected $fillable = ['channel', 'marker'];

    protected function casts(): array
    {
        return ['marker' => 'integer'];
    }
}
