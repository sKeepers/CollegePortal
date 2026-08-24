<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigitalIdentity extends Model
{
    public const ENTITY_STUDENT = 'student';
    public const ENTITY_TEACHER = 'teacher';
    public const ENTITY_EMPLOYEE = 'employee';

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
            self::ENTITY_EMPLOYEE => Employee::with(['person', 'primaryDepartment', 'primaryPosition'])->find($this->entity_id),
            default => null,
        };
    }

    /**
     * Есть ли ещё тот, кому пропуск выдан.
     *
     * Связи с владельцем у пропуска нет: `entity_type` и `entity_id` указывают
     * в три разные таблицы, и внешнего ключа не заведено ни к одной. Значит
     * удаление человека пропуск не уносит — тот остаётся действующим и
     * **открывает турникет**, а на экране охраны читается как сбой считывателя.
     * Проверено на стенде: сирот три, все отозванные, но отозваны они были
     * отдельным действием, а не удалением владельца.
     *
     * Спрашивается только существование, без загрузки владельца и его связей:
     * проверка стоит на пути каждого сканирования. Помеченный удалённым
     * владелец тоже считается отсутствующим — человек в корзине через проходную
     * не ходит.
     */
    public function ownerExists(): bool
    {
        return match ($this->entity_type) {
            self::ENTITY_STUDENT => Student::query()->whereKey($this->entity_id)->exists(),
            self::ENTITY_TEACHER => Teacher::query()->whereKey($this->entity_id)->exists(),
            self::ENTITY_EMPLOYEE => Employee::query()->whereKey($this->entity_id)->exists(),
            default => false,
        };
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
