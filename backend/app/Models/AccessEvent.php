<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessEvent extends Model
{
    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';

    public const RESULT_ALLOWED = 'allowed';
    public const RESULT_DENIED = 'denied';

    protected $fillable = [
        'digital_identity_id',
        'entity_type',
        'entity_id',
        'direction',
        'event_time',
        'access_point',
        'device_name',
        'result',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'event_time' => 'datetime',
        ];
    }

    public function digitalIdentity(): BelongsTo
    {
        return $this->belongsTo(DigitalIdentity::class);
    }

    public function getOwnerAttribute(): ?Model
    {
        return match ($this->entity_type) {
            DigitalIdentity::ENTITY_STUDENT => Student::with('group')->find($this->entity_id),
            DigitalIdentity::ENTITY_TEACHER => Teacher::find($this->entity_id),
            DigitalIdentity::ENTITY_EMPLOYEE => Employee::with('person')->find($this->entity_id),
            default => null,
        };
    }
}
