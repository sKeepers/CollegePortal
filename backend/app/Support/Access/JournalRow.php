<?php

namespace App\Support\Access;

use Carbon\CarbonImmutable;

/**
 * Одно событие чужого журнала, приведённое к нашим понятиям.
 *
 * Всё, что относится к конкретному контроллеру — коды типов событий, формат
 * времени, кодировка выгрузки — кончается здесь. Дальше служба загрузки видит
 * проход, а не строку CARDDEX: в день, когда придёт протокол, меняется только
 * источник этих строк.
 */
final readonly class JournalRow
{
    public function __construct(
        public string $externalId,
        public CarbonImmutable $eventTime,
        public ?string $deviceId,
        public ?string $cardUid,
        public string $result,
        public ?string $reason = null,
    ) {
    }

    /**
     * Ключ «тот же проход»: одна карта, одно устройство, та же доля секунды.
     *
     * Нужен не для уникальности в базе, а чтобы посчитать, сколько раз сам
     * контроллер записал один физический проход дважды. В копии действующей
     * СКУД таких пар 229 — с разными идентификаторами событий, то есть внешний
     * идентификатор их дублями не считает и не должен.
     */
    public function passKey(): string
    {
        return implode('|', [
            $this->cardUid ?? '-',
            $this->deviceId ?? '-',
            $this->eventTime->format('Y-m-d H:i:s.u'),
        ]);
    }
}
