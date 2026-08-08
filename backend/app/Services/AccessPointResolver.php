<?php

namespace App\Services;

use App\Models\AccessPoint;

/**
 * Сканер знает про точку прохода только строку, которую в него прописали при
 * установке, и переписывать прошивки ради справочника никто не будет. Поэтому
 * связь события со справочником восстанавливается по названию или коду, без
 * учета регистра и лишних пробелов. Не нашли — событие остается со строкой и
 * без связи: терять проход из-за опечатки в названии точки нельзя.
 */
class AccessPointResolver
{
    public function resolve(?string $name): ?AccessPoint
    {
        $needle = mb_strtolower(trim((string) $name));

        if ($needle === '') {
            return null;
        }

        return AccessPoint::query()
            ->where('is_active', true)
            ->get(['id', 'building_id', 'name', 'code'])
            ->first(fn (AccessPoint $point): bool => mb_strtolower(trim($point->name)) === $needle
                || ($point->code !== null && mb_strtolower(trim($point->code)) === $needle));
    }
}
