<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

class SeedDemoDatabaseCommand extends Command
{
    protected $signature = 'demo:seed {--reset : Clear existing DEMO-002 data before seeding}';

    protected $description = 'Create a synthetic CollegePortal demonstration database for DEV stands.';

    public function handle(DemoDataSeeder $demo): int
    {
        if ((bool) $this->option('reset')) {
            $demo->resetDemo();
        }

        $summary = $demo->seedDemo();
        $this->info('Demo database is ready. No real personal data was used.');
        $this->table(['Entity', 'Count'], collect($summary)->map(fn ($value, $key) => [$key, is_bool($value) ? ($value ? 'yes' : 'no') : $value])->values()->all());

        return self::SUCCESS;
    }
}