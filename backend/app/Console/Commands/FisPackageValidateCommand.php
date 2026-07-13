<?php

namespace App\Console\Commands;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\FisPackageValidator;
use Illuminate\Console\Command;

class FisPackageValidateCommand extends Command
{
    protected $signature = 'fis:package:validate {id}';
    protected $description = 'Validate an outbound FIS package against configured official XSD.';

    public function handle(FisPackageValidator $validator): int
    {
        $result = $validator->validate(FisOutboundPackage::findOrFail((int) $this->argument('id')));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
