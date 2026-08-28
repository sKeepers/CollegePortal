<?php

namespace App\Support\Rfid;

use RuntimeException;

/**
 * Разбор кадровой выгрузки CARDDEX: человек и номер его карты.
 *
 * Формат разобран замером 28.08.2026 по четырём файлам владельца, а не по
 * заголовку — заголовку здесь верить нельзя. Он обещает шесть колонок
 * (`Фамилия;Имя;Отчество;Карта;Статус;Подразделение;`), а строка данных несёт
 * **пять** значений, потому что фамилия и имя слиты в одно поле:
 *
 *     Ф'Иванов'Иван';'Иванович';1234567;сотрудник;Администрация;
 *
 * То есть всё правее второй колонки сдвинуто на одну влево относительно
 * заголовка: номер карты стоит в колонке, подписанной «Отчество». Загрузчик,
 * сопоставляющий колонки по именам заголовка, разложит это неверно и не
 * заметит — значения-то непустые.
 *
 * Кодировка CP1251, разделитель `;`, обрамление значений — апостроф.
 */
final class CarddexPeopleCsv
{
    /**
     * Незаполненные места шаблона выгрузки.
     *
     * 28.08.2026 владелец принёс четыре файла, и в одном из них — студенческом,
     * 636 строк — **не подставилось ни одно имя**: вместо фамилий стояли
     * `Студент1` … `Студент636` подряд без пропусков, на месте имени слово
     * `Имя`, на месте отчества `Отчество`. Номера карт при этом были
     * настоящие: 833 из 880 сошлись с базой СКУД.
     *
     * Такой файл опасен именно тем, что выглядит рабочим. Поэтому разбор
     * отказывается от файла **целиком**, а не пропускает подозрительные
     * строки: наполовину выгруженное нельзя загрузить наполовину.
     */
    private const PLACEHOLDERS = ['Имя', 'Отчество', 'Фамилия', 'ФИО'];

    /**
     * @return array<int, array{last_name: string, first_name: string, middle_name: string, card: string, status: string, department: string, line: int}>
     */
    public static function rows(string $path): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Файл не читается: {$path}");
        }

        $rows = [];
        $placeholders = 0;
        $lineNumber = 0;

        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            $line = rtrim(mb_convert_encoding($line, 'UTF-8', 'CP1251'), "\r\n");

            if ($lineNumber === 1 || trim($line) === '') {
                continue;
            }

            $fields = explode(';', $line);

            if (count($fields) < 5) {
                continue;
            }

            // Первое поле — `Ф'Фамилия'Имя'`: режем по апострофу и берём второй
            // и третий кусок. Первый («Ф») и четвёртый (пустой) — мусор
            // шаблона, значения не несут.
            $parts = explode("'", $fields[0]);
            $lastName = trim($parts[1] ?? '');
            $firstName = trim($parts[2] ?? '');
            $middleName = trim(trim($fields[1]), "'");

            if (self::isPlaceholder($lastName) || self::isPlaceholder($firstName) || self::isPlaceholder($middleName)) {
                $placeholders++;
            }

            $rows[] = [
                'last_name' => $lastName,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'card' => trim($fields[2]),
                'status' => trim($fields[3]),
                'department' => trim($fields[4]),
                'line' => $lineNumber,
            ];
        }

        fclose($handle);

        if ($placeholders > 0) {
            throw new RuntimeException(
                "Выгрузка неполна: в {$placeholders} строках из ".count($rows).' на месте имени стоит место '
                .'шаблона («Студент1», «Имя», «Отчество»), а не человек. Такой файл загружать нельзя: номера '
                .'карт в нём настоящие, а привязать их не к кому. Попросите выгрузить заново.',
            );
        }

        return $rows;
    }

    private static function isPlaceholder(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        // `Студент123` — порядковый номер строки, а не фамилия.
        if (preg_match('/^(Студент|Сотрудник|Фамилия|Имя)\d+$/u', $value) === 1) {
            return true;
        }

        return in_array($value, self::PLACEHOLDERS, true);
    }
}
