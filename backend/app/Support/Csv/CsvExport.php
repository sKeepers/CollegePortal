<?php

namespace App\Support\Csv;

use App\Services\AuditLogService;
use Illuminate\Http\Request;
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
     * Параметры отбора, значения которых в журнал не пишутся: человек ищет по
     * фамилии, и записанный запрос сам стал бы персональными данными.
     *
     * @var list<string>
     */
    private const FREE_TEXT_KEYS = ['search', 'q', 'query', 'name', 'full_name', 'fio'];

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
        // Кто выгружает и по какому отбору — снимается здесь, пока запрос ещё
        // разобран: тело потока выполняется позже, при отправке ответа.
        $request = request();
        $user = $request?->user();
        $filters = self::traceableFilters($request);

        return response()->streamDownload(function () use ($headers, $rows, $filename, $request, $user, $filters): void {
            $output = fopen('php://output', 'w');
            fwrite($output, self::BOM);

            if ($headers !== []) {
                fputcsv($output, $headers, self::DELIMITER, self::ENCLOSURE, self::ESCAPE);
            }

            $written = 0;

            $rows(static function (array $values) use ($output, &$written): void {
                fputcsv($output, $values, self::DELIMITER, self::ENCLOSURE, self::ESCAPE);
                $written++;
            });

            fclose($output);

            self::trace($filename, $written, $request, $user, $filters);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * След выгрузки: кто, когда, сколько строк и по какому отбору.
     *
     * Выгрузка — момент, когда данные покидают систему, и до 24.08.2026 портал
     * почти нигде не мог ответить, кто и что унёс: из семи выгрузок запись в
     * журнал оставляли две. Запись стоит здесь, а не в двадцати девяти местах
     * вызова, ровно по той же причине, по какой здесь стоит маркер порядка
     * байтов: одна забытая строка на одном конце — и следа нет.
     *
     * Самих данных в журнале быть не должно: смысл записи в том, чтобы не
     * заводить вторую копию паспортов рядом с первой.
     *
     * Пишется **после** обхода выборки, потому что до него неизвестно число
     * строк. Значит, у оборванной на середине выгрузки следа не останется —
     * но не останется и файла у того, кто её оборвал.
     *
     * @param  array<string, mixed>|null  $filters
     */
    private static function trace(string $filename, int $rows, ?Request $request, mixed $user, ?array $filters): void
    {
        AuditLogService::log('Exports', 'csv_exported', null, null, [
            'file' => $filename,
            'rows' => $rows,
            'path' => $request?->path(),
            'filters' => $filters,
        ], $request, $user);
    }

    /**
     * Отбор, по которому собран файл.
     *
     * Свободный текст поиска заменяется пометкой: по нему в журнал попала бы
     * фамилия, а запись нужна не для этого. Остальные параметры — группа,
     * статус, период — это и есть отбор, и они в журнале нужны: без них видно
     * «выгрузил 593 строки», но не видно, кого именно.
     *
     * @return array<string, mixed>|null
     */
    private static function traceableFilters(?Request $request): ?array
    {
        $filters = $request?->query() ?: [];

        if ($filters === []) {
            return null;
        }

        foreach (self::FREE_TEXT_KEYS as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = '[задан]';
            }
        }

        return $filters;
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
