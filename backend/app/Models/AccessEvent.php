<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessEvent extends Model
{
    public const DIRECTION_ENTRY = 'entry';
    public const DIRECTION_EXIT = 'exit';
    public const DIRECTION_IN = self::DIRECTION_ENTRY;
    public const DIRECTION_OUT = self::DIRECTION_EXIT;

    public const RESULT_ALLOWED = 'allowed';
    public const RESULT_DENIED = 'denied';

    protected $fillable = [
        'person_id',
        'access_point_id',
        'device_id',
        'operator_id',
        'request_id',
        'digital_identity_id',
        'entity_type',
        'entity_id',
        'direction',
        'event_time',
        'occurred_at',
        'access_point',
        'device_name',
        'result',
        'reason_code',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'event_time' => 'datetime',
            'occurred_at' => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AccessDevice::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function digitalIdentity(): BelongsTo
    {
        return $this->belongsTo(DigitalIdentity::class);
    }

    public function getOwnerAttribute(): ?Model
    {
        if ($this->person) {
            return $this->person;
        }

        return match ($this->entity_type) {
            DigitalIdentity::ENTITY_STUDENT => Student::with('group')->find($this->entity_id),
            DigitalIdentity::ENTITY_TEACHER => Teacher::find($this->entity_id),
            default => null,
        };
    }
}
