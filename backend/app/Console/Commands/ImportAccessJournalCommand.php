<?php

namespace App\Console\Commands;

use App\Services\AccessJournalImportService;
use App\Support\Access\CarddexCsvJournal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Загрузка журнала проходов из выгрузки чужой СКУД.
 *
 * Команда, а не экран: экрану нужно право, праву — миграция и решение
 * владельца о том, кому его выдать. Пока протокола обмена нет и загрузка
 * делается руками, консоли достаточно, и она ничего не занимает наперёд.
 */
class ImportAccessJournalCommand extends Command
{
    protected $signature = 'gate:import-journal
        {file : путь к выгрузке}
        {--source=carddex : источник, он же половина ключа «повтор не задваивает»}
        {--collapse-doubles : отбросить повторную запись одного прохода контроллером}
        {--dry-run : посчитать и откатить, ничего не записав}';

    protected $description = 'Загрузить журнал проходов из выгрузки СКУД в журнал портала';

    public function handle(AccessJournalImportService $service): int
    {
        $file = (string) $this->argument('file');

        if (! is_readable($file)) {
            $this->error("Файл не читается: {$file}");

            return self::FAILURE;
        }

        $source = (string) $this->option('source');

        if ($source !== CarddexCsvJournal::SOURCE) {
            $this->error("Разбор источника «{$source}» не написан. Сейчас известен только «".CarddexCsvJournal::SOURCE.'».');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            DB::beginTransaction();
        }

        $report = $service->import(
            $source,
            CarddexCsvJournal::rows($file),
            (bool) $this->option('collapse-doubles'),
        );

        if ($dryRun) {
            DB::rollBack();
        }

        $this->table(['Что', 'Сколько'], [
            ['принято строк', $report->received],
            ['записано', $report->imported],
            ['уже были', $report->alreadyPresent],
            ['устройство неизвестно', $report->skippedUnknownDevice],
            ['направление у устройства не задано', $report->skippedUnknownDirection],
            ['повторов одного прохода в источнике', $report->sourceDoubles],
            ['из них отброшено', $report->collapsed],
            ['без номера карты', $report->withoutCard],
            ['карта не заведена в портале', $report->unresolvedCard],
            ['нашёлся владелец', $report->resolved],
        ]);

        foreach ($report->devices as $device => $count) {
            $this->line("устройство {$device}: {$count}");
        }

        foreach ($report->unknownDevices as $device => $count) {
            $this->warn("устройство {$device} нет в справочнике, пропущено событий: {$count}");
        }

        if (! $report->matches()) {
            $this->error('Счётчики не сходятся с числом принятых строк — где-то потерялась строка.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Пробный прогон: записи откачены, в журнале ничего не появилось.');
        }

        return self::SUCCESS;
    }
}
