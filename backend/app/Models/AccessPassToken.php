<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessPassToken extends Model
{
    protected $fillable = ['person_id', 'token_hash', 'issued_at', 'expires_at', 'used_at', 'revoked_at', 'nonce', 'version', 'device_identifier'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime', 'version' => 'integer'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
