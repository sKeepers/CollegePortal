<?php

use App\Services\PersonService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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
