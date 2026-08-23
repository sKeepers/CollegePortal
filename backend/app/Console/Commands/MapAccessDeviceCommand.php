<?php

namespace App\Console\Commands;

use App\Models\AccessPointDevice;
use App\Services\AccessPointResolver;
use Illuminate\Console\Command;

/**
 * Правка справочника «устройство контроллера → наша точка прохода».
 *
 * Загрузка отказывается угадывать: устройства нет в справочнике — события с
 * него не принимаются, и в отчёте написано, какого номера не хватает. Эта
 * команда закрывает пробел, не заводя экрана и права.
 */
class MapAccessDeviceCommand extends Command
{
    protected $signature = 'gate:map-device
        {source : источник, например carddex}
        {device : номер устройства у контроллера}
        {point? : название или код нашей точки прохода}
        {--direction= : in или out — сторона двери, которую задаёт это устройство}
        {--name= : как называть устройство в журнале}
        {--remove : убрать соответствие}';

    protected $description = 'Сопоставить устройство контроллера СКУД с точкой прохода портала';

    public function handle(AccessPointResolver $points): int
    {
        $source = (string) $this->argument('source');
        $device = (string) $this->argument('device');

        if ($this->option('remove')) {
            $removed = AccessPointDevice::query()
                ->where('source', $source)
                ->where('external_id', $device)
                ->delete();

            $this->info($removed > 0 ? 'Соответствие убрано.' : 'Такого соответствия и не было.');

            return self::SUCCESS;
        }

        $name = (string) ($this->argument('point') ?? '');
        $point = $points->resolve($name);

        if ($point === null) {
            $this->error("Точка прохода «{$name}» не найдена. Смотрите справочник точек прохода.");

            return self::FAILURE;
        }

        $direction = $this->option('direction');

        if ($direction !== null && ! in_array($direction, ['in', 'out'], true)) {
            $this->error('Направление бывает только in или out.');

            return self::FAILURE;
        }

        $mapping = AccessPointDevice::query()->firstOrNew([
            'source' => $source,
            'external_id' => $device,
        ]);

        $mapping->fill([
            'access_point_id' => $point->id,
            'direction' => $direction,
            'name' => $this->option('name') ?: $mapping->name,
            'is_active' => true,
        ])->save();

        $this->info("Устройство {$device} источника «{$source}» → «{$point->name}»"
            .($direction === null ? ', направление не задано' : ", направление {$direction}"));

        return self::SUCCESS;
    }
}
