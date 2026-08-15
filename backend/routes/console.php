<?php

use App\Services\PersonService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('person:link-existing {--dry-run : Show planned links without writing data} {--apply : Create people and fill person_id links}', function (PersonService $personService): int {
    $apply = (bool) $this->option('apply');

    if (! $apply) {
        $this->line('Mode: dry-run. No database changes will be written.');
    }

    $summary = $personService->linkExisting($apply);

    $rows = collect($summary)
        ->reject(fn ($value, string $key) => $key === 'ambiguous')
        ->map(fn ($value, string $key) => [$key, is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value])
        ->values()
        ->all();

    $this->table(['Metric', 'Value'], $rows);

    if (! empty($summary['ambiguous'])) {
        $this->warn('Ambiguous duplicates were not linked automatically:');
        foreach ($summary['ambiguous'] as $item) {
            $this->line(sprintf(
                '- %s #%s %s candidates: %s',
                $item['profile_type'],
                $item['profile_id'],
                $item['name'],
                implode(',', $item['candidate_ids'])
            ));
        }
    }

    if (! $apply) {
        $this->info('Run with --apply to create Person records and fill person_id links in DEV.');
    }

    return self::SUCCESS;
})->purpose('Safely create Person records and link existing profiles.');

// Applicant document registry command is auto-discovered by Laravel.

/*
 * Первое расписание задач в проекте. `installer/docker-compose.yml` поднимает
 * `schedule:run` раз в минуту с самого начала, но регистрировать в нём было нечего —
 * значит, `NOTIFY-001` заодно впервые проверит, работает ли этот механизм на PROD.
 *
 * Очередь обновлений бота читается **одним** процессом: она общая и с указателем,
 * два читателя растащили бы события. Планировщик и есть этот один процесс.
 */
Schedule::command('notifications:max-pull')->everyMinute()->withoutOverlapping();

// Вечером, чтобы расписание на завтра пришло до конца дня, а не ночью.
Schedule::command('notifications:lessons-tomorrow')->dailyAt('19:00');

// Сводки за день идут после расписания и с зазором: два сообщения подряд в одну
// секунду выглядят как сбой, а не как две разные новости.
Schedule::command('notifications:journal-digest')->dailyAt('19:30');
