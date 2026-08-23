<?php

namespace App\Console\Commands;

use App\Services\Import\FisStudentEnrichmentService;
use Illuminate\Console\Command;

/**
 * Дополнить карточки уже заведённых студентов данными из выгрузок ФИС ГИА.
 *
 * Новых студентов команда не заводит: она только дописывает то, чего в карточках
 * нет, — СНИЛС, паспорт, гражданство, пол, место и дату рождения, адрес, приказ.
 *
 * **Без `--apply` команда ничего не пишет**, а показывает, что записала бы. Так и
 * положено начинать: сначала `--limit=10`, потом весь файл. На зачислении
 * контингента 22.08.2026 проба на десяти строках нашла три дефекта подряд,
 * каждый из которых на 593 строках прошёл бы незамеченным.
 *
 * Строку, которую автомат брать отказался — сменилась фамилия, разошлась дата
 * рождения, — можно назначить руками, разобрав случай:
 *
 * ```
 * php artisan students:fis-enrich /путь/2025.xls --pair=2025.xls:24=762 --apply
 * ```
 *
 * Даты приказа в выгрузке нет — она берётся из самого приказа и передаётся
 * `--order-date` по одной на файл, в том же порядке, что и файлы:
 *
 * ```
 * php artisan students:fis-enrich /путь/2023.xls /путь/2024.xls \
 *   --order-date=2023-08-18 --order-date=2024-08-16 --limit=10
 * ```
 */
class EnrichStudentsFromFisCommand extends Command
{
    protected $signature = 'students:fis-enrich
        {file* : Файлы выгрузки ФИС ГИА}
        {--order-date=* : Дата приказа о зачислении, по одной на файл в том же порядке}
        {--pair=* : Пара «файл:строка=карточка» для случаев, которые автомат не берёт}
        {--limit=0 : Взять только первые N строк каждого файла — проба}
        {--apply : Записать изменения; без флага команда только считает}
        {--report= : Куда положить построчный CSV-отчёт. Внимание: в нём ФИО, файл вне репозитория}';

    protected $description = 'Дополнить карточки студентов данными из выгрузки ФИС ГИА, не заводя новых.';

    public function __construct(private readonly FisStudentEnrichmentService $enrichment)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $paths = $this->argument('file');
        $orderDates = $this->option('order-date');
        $limit = (int) $this->option('limit');
        $apply = (bool) $this->option('apply');

        $files = [];
        foreach ($paths as $index => $path) {
            if (! is_file($path)) {
                $this->error('Файл не найден: '.$path);

                return self::FAILURE;
            }
            $files[] = [
                'path' => $path,
                'label' => basename($path),
                'order_date' => $orderDates[$index] ?? null,
            ];
        }

        $pairs = [];
        foreach ($this->option('pair') as $pair) {
            if (! preg_match('/^(?<place>[^=]+:\d+)=(?<student>\d+)$/', trim($pair), $parsed)) {
                $this->error('Пара записана неверно: '.$pair.'. Ожидается «2025.xls:24=762».');

                return self::FAILURE;
            }
            $pairs[$parsed['place']] = (int) $parsed['student'];
        }

        $summary = $this->enrichment->enrich($files, $apply, $limit > 0 ? $limit : null, $pairs);

        $this->renderSummary($summary, $apply, $limit);

        if ($path = $this->option('report')) {
            $this->writeReport($path, $summary);
            $this->comment('Отчёт: '.$path.'. В нём ФИО — держите его вне репозитория.');
        }

        if (! $apply) {
            $this->comment('Это был холостой проход. Записать — тот же вызов с --apply.');
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $summary */
    private function renderSummary(array $summary, bool $apply, int $limit): void
    {
        $this->info($apply ? 'Записано в карточки:' : 'Холостой проход, записано бы:');

        $this->table(['Файл', 'Строк', 'Обработано'], array_map(
            fn (array $file): array => [$file['label'], $file['rows'], $file['processed']],
            $summary['files'],
        ));

        if ($limit > 0) {
            $this->comment('Проба: из каждого файла взято не больше '.$limit.' строк.');
        }

        $this->table(['Сопоставление', 'Строк'], [
            ['по ФИО и дате рождения', $summary['matched']],
            ['по фамилии, имени и дате рождения (отчество разошлось)', $summary['matched_without_middle_name']],
            ['пара назначена человеком', $summary['matched_by_hand']],
            ['по одному ФИО, дата рождения в портале пуста', $summary['matched_by_name_only']],
            ['несколько подходящих студентов', $summary['ambiguous']],
            ['почти сошлось — решает человек', $summary['near_miss']],
            ['студента в портале нет', $summary['not_found']],
            ['повторная строка того же студента', $summary['repeat_rows']],
        ]);

        if ($summary['written'] !== []) {
            ksort($summary['written']);
            $this->table(['Поле', 'Заполнено'], array_map(
                fn (string $field, int $count): array => [$field, $count],
                array_keys($summary['written']),
                array_values($summary['written']),
            ));
        }

        if ($summary['conflicts'] !== []) {
            ksort($summary['conflicts']);
            $this->warn('Расхождения — портал не переписан, решает человек:');
            $this->table(['Что разошлось', 'Строк'], array_map(
                fn (string $field, int $count): array => [$field, $count],
                array_keys($summary['conflicts']),
                array_values($summary['conflicts']),
            ));
        }

        $this->line('Студентов в портале: '.$summary['students_total'].
            ', ни одной строкой не задето: '.$summary['students_untouched'].'.');
    }

    /** @param array<string, mixed> $summary */
    private function writeReport(string $path, array $summary): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $this->error('Отчёт не открылся на запись: '.$path);

            return;
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Файл', 'Строка', 'Категория', 'Студент в портале', 'ФИО в файле', 'Подробность'], ';', '"', '\\');

        foreach ($summary['issues'] as $issue) {
            fputcsv($handle, [
                $issue['file'],
                $issue['row'],
                $issue['category'],
                $issue['student_id'] ?? '',
                $issue['subject'] ?? '',
                $issue['detail'] ?? '',
            ], ';', '"', '\\');
        }

        fclose($handle);
    }
}
