<?php

namespace App\Console\Commands;

use App\Services\FisIntegration\FisSpecificationRegistry;
use Illuminate\Console\Command;

class FisSpecInfoCommand extends Command
{
    protected $signature = 'fis:spec-info';
    protected $description = 'Show loaded official FIS specification manifest and schema configuration.';

    public function handle(FisSpecificationRegistry $registry): int
    {
        $this->line(json_encode(['schema_version' => $registry->schemaVersion(), 'xsd_loaded' => (bool) $registry->xsdPath(), 'manifest' => $registry->manifest()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }
}
