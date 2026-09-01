<?php

/**
 * Что в файле расписания: сколько пар «та же группа, то же время» и чем они различаются.
 *
 * Заготовлено 01.09.2026, до прихода расписания. Владелец подтвердил, что в расписании есть
 * подгруппы с разными преподавателями и индивидуальные занятия у отдельных студентов, а портал
 * сегодня не примет ни того, ни другого: занятость группы блокирующая, признака подгруппы в
 * модели нет вовсе. Разбор — `docs/SCHEDULE_SUBGROUPS_AND_INDIVIDUAL_LESSONS.md`.
 *
 * Смысл этого скрипта — **не угадывать, как завуч помечает подгруппы, а прочитать это в файле**.
 * Он ничего не грузит и в базу не смотрит; отвечает на один вопрос: какие строки попадают в
 * одну клетку расписания и чем они между собой отличаются. Из ответа сразу видно, нужна ли
 * отдельная колонка, годится ли уже существующая и одна ли пометка на все случаи.
 *
 * Запуск (файл может быть .csv, .xlsx или .xls):
 *
 *   cd /home/andale/CollegePortal && docker run --rm -v "$PWD:/tree" \
 *     -v /home/andale/.cp-plans:/plans -w /tree collegeportal-backend \
 *     php -d memory_limit=1024M scripts/schedule/inspect-schedule-file.php "/plans/расписание.xlsx"
 *
 * Из worktree — тем же вызовом, но с примонтированным `backend/vendor` из основного
 * checkout: в worktree зависимостей нет.
 *
 * Второй довод — номер строки заголовка, если он не первый: `... файл.xlsx 9`.
 *
 * В выводе только числа, имена колонок и обезличенные образцы различий. Фамилий и списков
 * людей он не печатает: файл настоящий, а вывод может уехать в отчёт.
 */

// Скрипт лежит в `scripts/schedule/`, зависимости — в `backend/vendor`. Путь считается от
// файла, а не от текущего каталога: запускать его будут из разных мест и в контейнере.
require dirname(__DIR__, 2).'/backend/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = $argv[1] ?? null;
$headerRow = (int) ($argv[2] ?? 1);

if (! $path || ! is_file($path)) {
    fwrite(STDERR, "Укажите файл расписания: inspect-schedule-file.php <файл> [строка заголовка]\n");
    exit(1);
}

$rows = str_ends_with(mb_strtolower($path), '.csv') ? readCsv($path, $headerRow) : readSheet($path, $headerRow);

if ($rows === []) {
    fwrite(STDERR, "Ни одной строки данных не прочитано. Проверьте номер строки заголовка.\n");
    exit(1);
}

$headers = array_keys($rows[0]);

echo "Строк данных: ".count($rows)."\n";
echo "Колонок: ".count($headers)."\n";
foreach ($headers as $header) {
    $filled = count(array_filter($rows, static fn (array $row): bool => trim((string) $row[$header]) !== ''));
    echo "  «{$header}» — заполнено {$filled} из ".count($rows)."\n";
}

// Клетка расписания: группа плюс день плюс начало. Имена колонок ищутся по синонимам, потому
// что файл завуча ещё не видели: если какая-то не нашлась, скрипт скажет об этом прямо, а не
// покажет ноль пар и не создаст впечатления, что всё в порядке.
$group = pick($headers, ['группа', 'group']);
$date = pick($headers, ['дата', 'date', 'день']);
$start = pick($headers, ['время начала', 'начало', 'starts_at', 'время']);

foreach (['группа' => $group, 'дата' => $date, 'начало' => $start] as $label => $found) {
    if ($found === null) {
        fwrite(STDERR, "Не нашлась колонка «{$label}» — клетку расписания не собрать. Колонки файла перечислены выше; допишите синоним в pick() и запустите снова.\n");
        exit(1);
    }
}


// Корпус — первое, что надо узнать про файл расписания, и узнать до загрузки.
// 01.09.2026 в портале два корпуса, и **32 номера аудиторий есть в обоих**: 101-115,
// 201-213, 301-304. Строка с таким номером и без корпуса не «уйдёт не туда» — портал
// отказывает ей с внятной причиной, — но откажет каждой, и расписание не встанет вовсе.
// Поэтому проверка стоит здесь, до разбора клеток: если колонки нет, дальше смотреть
// уже не так важно.
$building = pick($headers, ['корпус', 'building']);

echo "\n";
if ($building === null) {
    echo "КОЛОНКИ КОРПУСА В ФАЙЛЕ НЕТ.\n";
    echo "  Номеров, встречающихся в двух корпусах, на 01.09.2026 — 32. Строки с такими\n";
    echo "  номерами откажут все до единой: «Аудитория с таким номером есть в нескольких\n";
    echo "  корпусах». Колонку надо добавить до загрузки, а не после.\n";
} else {
    $filled = count(array_filter($rows, static fn (array $row): bool => trim((string) $row[$building]) !== ''));
    $values = array_unique(array_map(static fn (array $row): string => trim((string) $row[$building]), $rows));
    sort($values);

    echo "Колонка корпуса: «{$building}», заполнена {$filled} из ".count($rows)."\n";
    echo "  Значения в файле: ".implode(', ', array_map(static fn (string $v): string => $v === '' ? '(пусто)' : $v, $values))."\n";
    echo "  В портале корпуса называются: Крупской, Голенева. Написание должно совпадать.\n";
}

// ——— Что из названного в файле портал не найдёт ———
//
// Спрашивается **тем же правилом**, которым спросит загрузка: у файла берутся колонки по
// синонимам загрузчика, строка прогоняется через его же `prepare()`, и смотрится, что он
// сумел разрешить. Своя копия правил разошлась бы с портальной в первый же день — а
// разойдясь, сказала бы «всё найдено» там, где загрузка откажет.
//
// Ничего не пишется: `prepare()` только читает справочники. Если базы нет — а завтра
// инструмент может понадобиться раньше доступа к ней, — проверка пропускается с одной
// строкой, а разбор клеток выше остаётся в силе.
echo "\n";

try {
    $bootstrap = dirname(__DIR__, 2).'/backend/bootstrap/app.php';

    if (! is_file($bootstrap)) {
        throw new RuntimeException('рядом нет backend/bootstrap/app.php');
    }

    $app = require $bootstrap;
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $handler = app(App\Services\Import\ScheduleImportHandler::class);
    $fields = $handler->fields();
    $mapping = [];

    foreach ($fields as $field => $meta) {
        foreach ($meta['aliases'] as $alias) {
            foreach ($headers as $header) {
                if (mb_strtolower(trim((string) $header)) === mb_strtolower($alias)) {
                    $mapping[$field] = $header;
                    break 2;
                }
            }
        }
    }

    // Что именно спрашивать про каждую ссылку: колонка с именем и поле с разрешённым id.
    $checks = [
        'аудитории' => ['id' => 'classroom_id', 'named' => ['classroom_number']],
        'группы' => ['id' => 'group_id', 'named' => ['group_name']],
        'преподаватели' => ['id' => 'teacher_id', 'named' => ['teacher_name']],
        'дисциплины' => ['id' => 'subject_id', 'named' => ['subject_name', 'subject_code']],
    ];

    $missing = [];
    $checked = 0;

    foreach ($rows as $row) {
        $mapped = [];
        foreach ($mapping as $field => $header) {
            $mapped[$field] = $row[$header] ?? null;
        }

        if ($mapped === []) {
            continue;
        }

        $checked++;
        $prepared = $handler->prepare($mapped);

        foreach ($checks as $label => $check) {
            $named = null;
            foreach ($check['named'] as $field) {
                if (filled($mapped[$field] ?? null)) { $named = trim((string) $mapped[$field]); break; }
            }

            if ($named === null) {
                continue;
            }

            $id = $prepared[$check['id']] ?? null;
            $verdict = match (true) {
                $id === App\Services\Import\ScheduleImportHandler::CLASSROOM_AMBIGUOUS => 'есть в нескольких корпусах',
                $id === App\Services\Import\ScheduleImportHandler::CLASSROOM_NOT_FOUND, $id === null => 'не найдено',
                default => null,
            };

            if ($verdict !== null) {
                $missing[$label][$named.' — '.$verdict] = ($missing[$label][$named.' — '.$verdict] ?? 0) + 1;
            }
        }
    }

    echo "Проверено строк по справочникам портала: {$checked}\n";

    if ($missing === []) {
        echo "  Всё названное в файле в портале есть.\n";
    }

    foreach ($missing as $label => $names) {
        arsort($names);
        echo "  ".mb_strtoupper($label).": имён ".count($names).", строк ".array_sum($names)."\n";
        foreach ($names as $name => $count) {
            echo "      «{$name}» — {$count} ".plural($count, 'строка', 'строки', 'строк')."\n";
        }
    }
} catch (Throwable $exception) {
    echo "Справочники портала не прочитаны, проверка имён пропущена: ".$exception->getMessage()."\n";
    echo "  Разбор клеток ниже от этого не зависит.\n";
}

echo "\nКлетка считается по колонкам: «{$group}» + «{$date}» + «{$start}»\n";

$cells = [];
foreach ($rows as $index => $row) {
    $key = trim((string) $row[$group]).'|'.trim((string) $row[$date]).'|'.trim((string) $row[$start]);
    $cells[$key][] = $index;
}

$shared = array_filter($cells, static fn (array $indexes): bool => count($indexes) > 1);

echo "Клеток всего: ".count($cells)."\n";
echo "Клеток с несколькими строками: ".count($shared)."\n";
echo "Строк в таких клетках: ".array_sum(array_map('count', $shared))."\n";

if ($shared === []) {
    echo "\nПодгрупп и параллельных занятий в файле нет: каждая клетка занята одной строкой.\n";
    echo "Тогда достаточно развести тождество и занятость, новая колонка не нужна.\n";
    exit(0);
}

// Чем строки одной клетки отличаются друг от друга — это и есть ответ на вопрос,
// чем портал должен их различать.
$differByCount = [];
$examples = [];

foreach ($shared as $key => $indexes) {
    $differ = [];

    foreach ($headers as $header) {
        $values = array_unique(array_map(static fn (int $i): string => trim((string) $rows[$i][$header]), $indexes));

        if (count($values) > 1) {
            $differ[] = $header;
        }
    }

    $signature = $differ === [] ? '(ничем — полные дубли)' : implode(' + ', $differ);
    $differByCount[$signature] = ($differByCount[$signature] ?? 0) + 1;

    if (! isset($examples[$signature])) {
        $examples[$signature] = array_map(
            static fn (string $header): string => $header.': '.implode(' / ', array_unique(array_map(
                static fn (int $i): string => shorten(trim((string) $rows[$i][$header])), $indexes))),
            $differ === [] ? [] : $differ,
        );
    }
}

arsort($differByCount);

echo "\nЧем различаются строки в одной клетке:\n";
foreach ($differByCount as $signature => $count) {
    echo '  '.$count.' '.plural($count, 'клетка', 'клетки', 'клеток')." — различаются: {$signature}\n";
    foreach ($examples[$signature] as $line) {
        echo "      {$line}\n";
    }
}



echo "\nЧто это значит:\n";
echo "  — различаются только преподавателем или аудиторией: это подгруппы, и портал их\n";
echo "    сегодня не различит; нужна пометка, которой в файле нет.\n";
echo "  — среди различий есть колонка с номером или буквой: она и есть пометка подгруппы,\n";
echo "    её надо принять как колонку «Подгруппа».\n";
echo "  — «ничем — полные дубли»: строки повторяются, и это вопрос к завучу, а не к порталу.\n";

/**
 * Первое совпадение имени колонки по списку синонимов, без учёта регистра.
 *
 * Синонимы перебираются снаружи, а колонки внутри, и это не мелочь: при обратном
 * порядке «Время окончания» перехватило бы синоним «время» раньше, чем «Время
 * начала» дошло бы до своего, — и клетка расписания считалась бы по концу пары.
 */
function pick(array $headers, array $synonyms): ?string
{
    foreach ($synonyms as $synonym) {
        foreach ($headers as $header) {
            $normalized = mb_strtolower(trim((string) $header));

            if ($normalized === $synonym || str_starts_with($normalized, $synonym)) {
                return $header;
            }
        }
    }

    return null;
}

/** Образец значения: длинное обрезается, чтобы вывод оставался читаемым. */
function shorten(string $value): string
{
    return mb_strlen($value) > 40 ? mb_substr($value, 0, 37).'…' : ($value === '' ? '(пусто)' : $value);
}

/** @return array<int, array<string, string>> */
function readCsv(string $path, int $headerRow): array
{
    $handle = fopen($path, 'rb');
    $first = fgets($handle);
    rewind($handle);

    // Разделитель определяется по первой строке — тем же способом, что и у портала.
    $delimiter = substr_count((string) $first, ';') >= substr_count((string) $first, ',') ? ';' : ',';
    $rows = [];
    $headers = null;
    $line = 0;

    while (($cells = fgetcsv($handle, 0, $delimiter, chr(34), "")) !== false) {
        $line++;

        if ($line < $headerRow) {
            continue;
        }

        // BOM снимается **один раз и только с начала заголовка**. Прежде здесь стоял
        // `trim($value, "\xEF\xBB\xBF \t\n\r")`, и это не «убрать BOM», а «убрать с краёв
        // любой из этих байтов»: «л» в UTF-8 — это D0 BB, «п» — D0 BF, и у имени,
        // кончающегося на такую букву, съедался последний байт. «Большой зал»
        // превращался в «большой за» с обломком и не находился в портале — то есть
        // разбор врал бы «аудитории нет» про исправную аудиторию.
        $cells = array_map(static fn ($value): string => trim((string) $value), $cells);

        if ($headers === null) {
            $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0] ?? '');
            $headers = $cells;
            continue;
        }

        if (implode('', $cells) === '') {
            continue;
        }

        $rows[] = array_combine($headers, array_pad(array_slice($cells, 0, count($headers)), count($headers), ''));
    }

    fclose($handle);

    return $rows;
}

/** @return array<int, array<string, string>> */
function readSheet(string $path, int $headerRow): array
{
    // Лист не всегда первый и называется по-разному — в учебных планах он звался и
    // «Титульный», и «ПОСЛЕДНИЙ». Берём тот, где строка заголовка непустая.
    $spreadsheet = IOFactory::createReaderForFile($path)->setReadDataOnly(true)->load($path);
    $best = [];

    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
        $table = $sheet->toArray(null, true, false, false);

        if (count($table) <= $headerRow) {
            continue;
        }

        $headers = array_map(static fn ($value): string => trim((string) $value), $table[$headerRow - 1]);

        if (count(array_filter($headers)) < 3) {
            continue;
        }

        $rows = [];

        foreach (array_slice($table, $headerRow) as $cells) {
            $cells = array_map(static fn ($value): string => trim((string) $value), $cells);

            if (implode('', $cells) === '') {
                continue;
            }

            $rows[] = array_combine($headers, array_pad(array_slice($cells, 0, count($headers)), count($headers), ''));
        }

        if (count($rows) > count($best)) {
            $best = $rows;
        }
    }

    return $best;
}

/** Склонение существительного при числе: «1 клетка», «2 клетки», «5 клеток». */
function plural(int $count, string $one, string $few, string $many): string
{
    $mod100 = $count % 100;
    $mod10 = $count % 10;

    if ($mod100 >= 11 && $mod100 <= 14) {
        return $many;
    }

    return match (true) {
        $mod10 === 1 => $one,
        $mod10 >= 2 && $mod10 <= 4 => $few,
        default => $many,
    };
}
