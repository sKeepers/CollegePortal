<?php

namespace App\Jobs;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\FisPackageStatusService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshFisPackageStatusJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $packageId, public bool $mock = false) {}

    public function handle(FisPackageStatusService $service): void
    {
        $service->refresh(FisOutboundPackage::findOrFail($this->packageId), $this->mock);
    }
}
