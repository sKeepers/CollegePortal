<?php

namespace App\Console\Commands;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\FisPackageStatusService;
use Illuminate\Console\Command;

class FisPackageStatusCommand extends Command
{
    protected $signature = 'fis:package:status {id} {--mock : Use mock transport}';
    protected $description = 'Refresh outbound FIS package status.';

    public function handle(FisPackageStatusService $service): int
    {
        $result = $service->refresh(FisOutboundPackage::findOrFail((int) $this->argument('id')), (bool) $this->option('mock'));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }
}
PHP
