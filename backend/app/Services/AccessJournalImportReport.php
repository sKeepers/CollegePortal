<?php

namespace App\Services;

/**
 * Что стало с каждой строкой чужого журнала.
 *
 * Загрузка, которая отвечает одним числом «загружено», бесполезна: пропавшие
 * проходы в ней неотличимы от отсутствующих. Поэтому каждая строка обязана
 * попасть ровно в один счётчик, и сумма разошедшихся сходится с принятыми —
 * это проверяет `matches()`.
 */
final class AccessJournalImportReport
{
    /** Строк принято от источника. */
    public int $received = 0;

    /** Событий записано в журнал. */
    public int $imported = 0;

    /** Уже были в журнале: тот же источник и тот же внешний идентификатор. */
    public int $alreadyPresent = 0;

    /** Устройства нет в справочнике — направление взять неоткуда. */
    public int $skippedUnknownDevice = 0;

    /** Устройство в справочнике есть, а направление у него не задано. */
    public int $skippedUnknownDirection = 0;

    /** Один физический проход, записанный контроллером дважды. */
    public int $sourceDoubles = 0;

    /** Из них отброшено по требованию вызвавшего. */
    public int $collapsed = 0;

    /** Событий без номера карты. */
    public int $withoutCard = 0;

    /** Карта в событии есть, а в портале её нет: проход остался без человека. */
    public int $unresolvedCard = 0;

    /** Событий, у которых нашёлся владелец. */
    public int $resolved = 0;

    /** @var array<string, int> */
    public array $devices = [];

    /** @var array<string, int> */
    public array $unknownDevices = [];

    public function countDevice(?string $device, bool $known): void
    {
        $key = $device ?? '(нет устройства)';

        if ($known) {
            $this->devices[$key] = ($this->devices[$key] ?? 0) + 1;

            return;
        }

        $this->unknownDevices[$key] = ($this->unknownDevices[$key] ?? 0) + 1;
    }

    /** Каждая принятая строка учтена ровно один раз. */
    public function matches(): bool
    {
        return $this->received === $this->imported
            + $this->alreadyPresent
            + $this->skippedUnknownDevice
            + $this->skippedUnknownDirection
            + $this->collapsed;
    }

    /** @return array<string, int|array<string, int>> */
    public function toArray(): array
    {
        return [
            'received' => $this->received,
            'imported' => $this->imported,
            'already_present' => $this->alreadyPresent,
            'skipped_unknown_device' => $this->skippedUnknownDevice,
            'skipped_unknown_direction' => $this->skippedUnknownDirection,
            'source_doubles' => $this->sourceDoubles,
            'collapsed' => $this->collapsed,
            'without_card' => $this->withoutCard,
            'unresolved_card' => $this->unresolvedCard,
            'resolved' => $this->resolved,
            'devices' => $this->devices,
            'unknown_devices' => $this->unknownDevices,
        ];
    }
}
