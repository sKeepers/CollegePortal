<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

class ResetDemoDatabaseCommand extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Remove synthetic DEMO-002 records from the DEV database.';

    public function handle(DemoDataSeeder $demo): int
    {
        $result = $demo->resetDemo();
        $this->info('Demo database records were removed.');
        $this->table(['Entity', 'Deleted'], collect($result['deleted'])->map(fn ($value, $key) => [$key, $value])->values()->all());

        return self::SUCCESS;
    }
}