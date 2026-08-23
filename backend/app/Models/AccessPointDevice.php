<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Устройство чужого контроллера, сопоставленное с нашей точкой прохода.
 *
 * Живой сканер говорит о себе строкой («Главный вход»), и её разбирает
 * `AccessPointResolver`. Контроллер СКУД так не умеет: он называет устройство
 * номером, и номер этот ничего не значит за пределами самого контроллера. В
 * копии действующей СКУД проходная — это устройства 2 и 3 одного контроллера,
 * и по названию их не узнать никак.
 *
 * Поэтому соответствие хранится явно, а не выводится. Заодно устройство несёт
 * **направление**: у контроллера отдельного поля «вход/выход» в событии нет
 * вовсе, сторона двери задаётся тем, какой считыватель сработал.
 */
class AccessPointDevice extends Model
{
    protected $fillable = [
        'source',
        'external_id',
        'access_point_id',
        'direction',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class);
    }
}
