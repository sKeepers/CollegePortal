<?php

namespace App\Jobs;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\FisPackageSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendFisPackageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $packageId, public bool $mock = false) {}

    public function handle(FisPackageSender $sender): void
    {
        $package = FisOutboundPackage::findOrFail($this->packageId);
        $sender->send($package, preview: false, mock: $this->mock);
    }
}
