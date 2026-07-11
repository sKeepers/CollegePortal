<?php

namespace App\Console\Commands;

use App\Services\PersonService;
use Illuminate\Console\Command;

class LinkExistingPeopleCommand extends Command
{
    protected $signature = 'person:link-existing {--dry-run : Show planned links without writing data} {--apply : Create people and fill person_id links}';

    protected $description = 'Safely create Person records and link existing student, teacher, applicant and graduate profiles.';

    public function handle(PersonService $personService): int
    {
        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->line('Mode: dry-run. No database changes will be written.');
        }

        $summary = $personService->linkExisting($apply);

        $this->table(['Metric', 'Value'], collect($summary)
            ->reject(fn ($value, string $key) => $key === 'ambiguous')
            ->map(fn ($value, string $key) => [$key, is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value])
            ->values()
            ->all());

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
    }
}
