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
        'access_point_id',
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

    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class);
    }

    /**
     * Владелец, найденный заранее для целой пачки событий.
     *
     * Обычное свойство, а не атрибут: присваивание идёт мимо `setAttribute`, в
     * `toArray()` оно не попадает и в базу не пишется. Заполняет его
     * `App\Services\AccessEventOwners::attach`.
     */
    public ?Model $resolvedOwner = null;

    /**
     * Владелец пропуска: `entity_type` и `entity_id` указывают в три разные
     * таблицы, поэтому это аксессор, а не связь, и `with()` его не подтянет.
     *
     * **Обращение к нему в цикле — запрос на каждую строку.** Список эвакуации
     * на этом уже ловили (598 человек — 1129 запросов), отчёт проходной поймали
     * следом: одна страница стоила 1810 запросов. Для списков сначала зовите
     * `AccessEventOwners::attach`, тогда здесь вернётся уже найденное.
     */
    public function getOwnerAttribute(): ?Model
    {
        if ($this->resolvedOwner !== null) {
            return $this->resolvedOwner;
        }

        return match ($this->entity_type) {
            DigitalIdentity::ENTITY_STUDENT => Student::with('group')->find($this->entity_id),
            DigitalIdentity::ENTITY_TEACHER => Teacher::find($this->entity_id),
            DigitalIdentity::ENTITY_EMPLOYEE => Employee::with(['person', 'primaryDepartment'])->find($this->entity_id),
            default => null,
        };
    }
}
