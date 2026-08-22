<?php

namespace Tests\Feature;

use App\Support\Csv\CsvExport;
use App\Support\Csv\CsvImport;
use Tests\TestCase;

/**
 * Экранирование в CSV передаётся явно и равно пустому.
 *
 * С PHP 8.4 умолчание у `$escape` объявлено устаревшим, и в PHP 9 оно
 * сменится: файлы начнут разбираться иначе без единой правки в нашем коде.
 * Пока умолчанием был обратный слэш, он считался экранирующим — и значение
 * вида `C:\путь\` съедало следующий разделитель, склеивая поле с соседним.
 *
 * Проверяется две вещи: что предупреждение больше не поднимается ни на одном
 * вызове, и что поле со слэшем, кавычкой и разделителем внутри доходит до
 * загрузки ровно тем же, чем ушло из выгрузки.
 */
class CsvEscapeIsExplicitTest extends TestCase
{
    /**
     * Значения подобраны так, чтобы задеть все три особых символа сразу:
     * обратный слэш в конце поля, кавычка внутри текста и разделитель.
     */
    private const ROW = [
        'C:\\общая папка\\',
        'кабинет "малый зал"',
        'Иванов; Петров',
        'обычное значение',
    ];

    public function test_a_backslash_survives_the_round_trip(): void
    {
        $path = $this->writeFile();

        $rows = iterator_to_array(CsvImport::rows($path));
        $first = reset($rows);

        $this->assertSame(
            self::ROW,
            array_values($first),
            'Поле со слэшем, кавычкой или разделителем изменилось при проходе через выгрузку и загрузку.',
        );

        unlink($path);
    }

    /**
     * Предупреждение не видно пользователю — оно уходит в журнал, — поэтому
     * ловим его обработчиком ошибок, а не глазами. Без явного `$escape` этот
     * тест краснеет на каждом из вызовов.
     */
    public function test_no_deprecation_is_raised_by_reading_or_writing(): void
    {
        $deprecations = [];

        set_error_handler(function (int $level, string $message) use (&$deprecations): bool {
            if ($level === E_DEPRECATED && str_contains($message, 'escape')) {
                $deprecations[] = $message;
            }

            return true;
        }, E_DEPRECATED);

        try {
            $path = $this->writeFile();
            iterator_to_array(CsvImport::rows($path));
            CsvImport::hasHeader($path);
            unlink($path);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations, 'Вызов CSV остался без явного $escape: '.implode('; ', $deprecations));
    }

    /**
     * Файл пишется тем же кодом, что и настоящие выгрузки, — иначе проверялся
     * бы не шов, а сам тест.
     */
    private function writeFile(): string
    {
        $headers = ['Путь', 'Кабинет', 'Люди', 'Прочее'];
        $content = CsvExport::toString([$headers, self::ROW]);

        $path = tempnam(sys_get_temp_dir(), 'csv').'.csv';
        file_put_contents($path, $content);

        return $path;
    }
}
