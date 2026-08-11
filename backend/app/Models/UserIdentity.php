<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserIdentity extends Model
{
    /**
     * `chat_id` и `chat_started_at` заполняются вторым шагом привязки, когда человек
     * нажал «Старт» у бота: до этого писать ему некуда — бот не может начать диалог сам.
     */
    protected $fillable = [
        'user_id', 'provider', 'provider_user_id', 'chat_id', 'chat_started_at',
        'display_name', 'linked_at', 'linked_by',
    ];

    protected function casts(): array
    {
        return ['linked_at' => 'datetime', 'chat_started_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
