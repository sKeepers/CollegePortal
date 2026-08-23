<?php

namespace App\Support\Access;

use App\Models\AccessEvent;
use App\Support\Csv\CsvImport;
use App\Support\Rfid\CardNumber;
use Carbon\CarbonImmutable;
use Generator;

/**
 * Журнал контроллера CARDDEX, разобранный из выгрузки.
 *
 * Пока протокола обмена нет, журнал приезжает файлом; когда протокол придёт,
 * на его место встанет другой источник тех же `JournalRow`, а служба загрузки
 * не изменится. Ради этого здесь и заперто всё, что знает про CARDDEX.
 *
 * К проходу относятся три типа события из сорока с лишним. Остальные — это
 * заведение карточек, правка сотрудников, связь с сервером и механика самого
 * турникета: створка открылась (53) и закрылась (54) идут почти парой к
 * каждому разрешённому проходу, и если принять их за проходы, журнал вырастет
 * втрое, а присутствие в здании перевернётся на каждом человеке.
 */
final class CarddexCsvJournal
{
    public const SOURCE = 'carddex';

    private const TYPE_ALLOWED = '50';
    private const TYPE_UNKNOWN_CARD = '46';
    private const TYPE_INVALID_CARD = '65';

    /** @return Generator<int, JournalRow> */
    public static function rows(string $path): Generator
    {
        foreach (CsvImport::rows($path) as $line => $row) {
            $type = trim((string) ($row['event_type'] ?? ''));

            if (! in_array($type, [self::TYPE_ALLOWED, self::TYPE_UNKNOWN_CARD, self::TYPE_INVALID_CARD], true)) {
                continue;
            }

            $externalId = trim((string) ($row['external_id'] ?? ''));
            $time = trim((string) ($row['event_time'] ?? ''));

            if ($externalId === '' || $time === '') {
                continue;
            }

            // Номер карты приводим к общему виду тем же кодом, что и живой
            // считыватель: в выгрузке он уже десятизначный, но полагаться на
            // это нельзя — настройку считывателя меняют.
            $card = CardNumber::tryNormalize((string) ($row['card'] ?? ''));
            $device = trim((string) ($row['device'] ?? ''));

            yield $line => new JournalRow(
                externalId: $externalId,
                eventTime: CarbonImmutable::parse($time),
                deviceId: $device === '' ? null : $device,
                cardUid: $card,
                result: $type === self::TYPE_ALLOWED ? AccessEvent::RESULT_ALLOWED : AccessEvent::RESULT_DENIED,
                reason: self::reason($type, $card),
            );
        }
    }

    /**
     * Причина отказа словами — от контроллера, а не от нас.
     *
     * Отказал он, и по своему справочнику карт: карта, которой у него нет,
     * вполне может быть заведена у нас, и наоборот. Подменять его причину
     * своей — значит записать в журнал то, чего не было.
     */
    private static function reason(string $type, ?string $card): ?string
    {
        $number = $card ?? 'без номера';

        return match ($type) {
            self::TYPE_UNKNOWN_CARD => "Контроллер СКУД: карта {$number} ему не известна.",
            // Какой именно это отказ — блокировка, утеря или списание —
            // контроллер не различает, у него на всё один тип события.
            self::TYPE_INVALID_CARD => "Контроллер СКУД: карта {$number} недействительна.",
            default => null,
        };
    }
}
