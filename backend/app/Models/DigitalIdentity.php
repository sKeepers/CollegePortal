<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigitalIdentity extends Model
{
    public const ENTITY_STUDENT = 'student';
    public const ENTITY_TEACHER = 'teacher';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'person_id',
        'entity_type',
        'entity_id',
        'token',
        'status',
        'issued_at',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function getOwnerAttribute(): ?Model
    {
        return match ($this->entity_type) {
            self::ENTITY_STUDENT => Student::with('group')->find($this->entity_id),
            self::ENTITY_TEACHER => Teacher::find($this->entity_id),
            default => null,
        };
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
