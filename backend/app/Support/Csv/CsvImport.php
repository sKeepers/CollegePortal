<?php

namespace App\Support\Csv;

use Generator;

/**
 * Чтение CSV для тех импортов, которые написаны прямо в контроллерах.
 *
 * Снимает три грабли разом.
 *
 * Маркер порядка байтов. Excel ставит его при сохранении в «CSV UTF-8», и он
 * приклеивается к первой колонке заголовка. У учебных планов, нагрузки и
 * экзаменов первая колонка — это id, по которому импорт отличает обновление от
 * создания. Потеряв её, импорт создавал новую запись на каждую строку файла и
 * сообщал об успехе: выгруженная нагрузка из тридцати строк возвращалась
 * тридцатью новыми нагрузками.
 *
 * Разделитель. Контроллеры жёстко требовали ';', хотя весь остальной портал уже
 * умел распознавать и запятую.
 *
 * Число колонок. array_combine с массивами разной длины в PHP 8 бросает
 * ValueError, то есть одна лишняя точка с запятой в конце строки роняла импорт
 * пятисотой ошибкой вместо внятного сообщения.
 *
 * Импорты справочников (*CsvService) сюда пока не переведены: они BOM снимают
 * сами и работают верно. Их место — в UniversalImportService, это отдельная
 * задача.
 */
final class CsvImport
{
    /**
     * Строки файла, сопоставленные с заголовками.
     *
     * Ключ — номер строки в файле, считая заголовок первой: сообщения об
     * ошибках ссылаются на строку, которую человек видит в таблице.
     *
     * @return Generator<int, array<string, string>>
     */
    public static function rows(string $path): Generator
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return;
        }

        try {
            $delimiter = self::detectDelimiter($path);
            self::skipByteOrderMark($handle);

            $headers = fgetcsv($handle, 0, $delimiter);

            if (! is_array($headers) || $headers === [null]) {
                return;
            }

            $headers = self::normalizeHeaders($headers);
            $width = count($headers);
            $line = 1;

            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $line++;

                if ($row === [null]) {
                    continue;
                }

                $row = array_slice(array_pad($row, $width, ''), 0, $width);

                yield $line => array_combine($headers, array_map(
                    static fn ($value) => trim((string) $value),
                    $row,
                ));
            }
        } finally {
            fclose($handle);
        }
    }

    private static function skipByteOrderMark($handle): void
    {
        if (fread($handle, 3) !== CsvExport::BOM) {
            rewind($handle);
        }
    }

    private static function detectDelimiter(string $path): string
    {
        $sample = file_get_contents($path, false, null, 0, 4096) ?: '';

        return substr_count($sample, ';') >= substr_count($sample, ',') ? ';' : ',';
    }

    /**
     * @param  list<mixed>  $headers
     * @return list<string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        return array_values(array_map(
            static fn ($header) => trim((string) $header, CsvExport::BOM." \t\n\r\0\x0B"),
            $headers,
        ));
    }
}
