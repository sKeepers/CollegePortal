<?php

namespace App\Support\Csv;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Единственное место, где портал формирует CSV на выгрузку.
 *
 * До этого каждая выгрузка сама открывала php://output и сама решала, писать ли
 * маркер порядка байтов. Из тринадцати проверенных его писали девять, поэтому
 * учебные планы, нагрузка, экзамены и выпускники открывались в Excel
 * кракозябрами — а человек, который видит кракозябры, пересохраняет файл, и
 * тогда маркер появляется уже во входном файле и ломает разбор первой колонки.
 * Одна забытая строка на одном конце оборачивалась порчей данных на другом.
 *
 * Здесь же закреплен разделитель. Раньше каждая выгрузка передавала ';' в
 * fputcsv вручную, и однажды кто-нибудь передал бы запятую.
 */
final class CsvExport
{
    /**
     * Точка с запятой, потому что русский Excel разбирает на столбцы именно её:
     * с запятой файл открывается одной колонкой.
     */
    public const DELIMITER = ';';

    /**
     * Кавычка и **пустое** экранирование, переданные явно.
     *
     * С PHP 8.4 умолчание у `$escape` объявлено устаревшим: каждый вызов
     * `fgetcsv`, `fputcsv` и `SplFileObject::setCsvControl` без него роняет
     * предупреждение, а в PHP 9 умолчание сменится, и файлы начнут
     * разбираться иначе без единой правки в нашем коде.
     *
     * Выбрана пустая строка, а не исторический обратный слэш. В обычном CSV,
     * который пишет и читает Excel, экранирования через слэш нет вовсе:
     * кавычка внутри поля удваивается. Пока слэш считался экранирующим,
     * значение вида `C:\путь\` съедало следующий символ, и поле приходило
     * склеенным с соседним. Пустое экранирование — это поведение будущего PHP
     * и одновременно правильное поведение для наших файлов.
     */
    public const ENCLOSURE = '"';

    public const ESCAPE = '';

    /**
     * Маркер порядка байтов. Без него Excel читает UTF-8 как ANSI и показывает
     * кириллицу кракозябрами.
     */
    public const BOM = "\xEF\xBB\xBF";

    /**
     * Выгрузка потоком: заголовок и строки пишутся по мере обхода выборки,
     * поэтому объём файла не упирается в память.
     *
     * @param  list<string>  $headers
     * @param  callable(callable(list<mixed>): void): void  $rows  получает функцию записи строки
     */
    public static function download(string $filename, array $headers, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $output = fopen('php://output', 'w');
            fwrite($output, self::BOM);

            if ($headers !== []) {
                fputcsv($output, $headers, self::DELIMITER, self::ENCLOSURE, self::ESCAPE);
            }

            $rows(static function (array $values) use ($output): void {
                fputcsv($output, $values, self::DELIMITER, self::ENCLOSURE, self::ESCAPE);
            });

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Тот же формат, но строкой — для шаблонов импорта и вложений.
     *
     * @param  list<list<mixed>>  $rows
     */
    public static function toString(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, self::BOM);

        foreach ($rows as $row) {
            fputcsv($handle, $row, self::DELIMITER, self::ENCLOSURE, self::ESCAPE);
        }

        rewind($handle);

        return stream_get_contents($handle) ?: '';
    }
}
