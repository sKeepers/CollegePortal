<?php

namespace App\Console\Commands;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\FisPackageBuilder;
use Illuminate\Console\Command;

class FisPackageGenerateCommand extends Command
{
    protected $signature = 'fis:package:generate {type} {--package-id=}';
    protected $description = 'Generate outbound FIS XML when official schema configuration is loaded.';

    public function handle(FisPackageBuilder $builder): int
    {
        $package = $this->option('package-id')
            ? FisOutboundPackage::findOrFail((int) $this->option('package-id'))
            : FisOutboundPackage::create(['package_type' => (string) $this->argument('type'), 'schema_version' => config('fis_api.schema_version'), 'environment' => 'test', 'status' => 'draft']);
        try {
            $package = $builder->generate($package);
            $this->info('Generated package #'.$package->id.' sha256='.$package->payload_sha256);
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
