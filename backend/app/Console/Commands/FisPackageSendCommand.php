<?php

namespace App\Console\Commands;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\FisPackageSender;
use Illuminate\Console\Command;

class FisPackageSendCommand extends Command
{
    protected $signature = 'fis:package:send {id} {--environment=test} {--mock : Use mock transport}';
    protected $description = 'Send an outbound FIS package to TEST/mocked transport. Production is blocked in FIS-API-001.';

    public function handle(FisPackageSender $sender): int
    {
        if ($this->option('environment') !== 'test') {
            $this->error('Production send is blocked in FIS-API-001.');
            return self::FAILURE;
        }
        $result = $sender->send(FisOutboundPackage::findOrFail((int) $this->argument('id')), preview: false, mock: (bool) $this->option('mock'));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
