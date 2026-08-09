<?php

namespace App\Services\FisIntegration;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\Exceptions\FisCompositionBlockedException;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use App\Services\FisIntegration\Xml\PackageDataComposer;
use Illuminate\Support\Facades\Storage;

class FisPackageBuilder
{
    public function __construct(
        private readonly FisSpecificationRegistry $registry,
        private readonly PackageDataComposer $composer,
    ) {
    }

    public function generate(FisOutboundPackage $package): FisOutboundPackage
    {
        if (! $this->registry->officialSchemaLoaded()) {
            throw new FisIntegrationException('Официальная схема ФИС не загружена: проверьте FIS_API_XSD_PATH и FIS_API_SCHEMA_VERSION. Сборка XML заблокирована, чтобы не выдумывать формат.');
        }

        $composition = $this->composer->compose($package);

        if ($composition->blocked()) {
            $this->event($package, 'generation_blocked', [
                'blocker_count' => count($composition->blockers),
                'blockers' => $composition->blockers,
            ]);

            throw new FisCompositionBlockedException($composition->blockers);
        }

        $path = 'fis/outbound/package-'.$package->id.'/payload.xml';
        Storage::disk('local')->put($path, $composition->xml);

        $package->update([
            'payload_path' => $path,
            'payload_sha256' => hash('sha256', $composition->xml),
            'schema_version' => $this->registry->schemaVersion(),
            'status' => 'generated',
            'last_error_code' => null,
            'last_error_message' => null,
        ]);

        // В событии только счётчики и хэш: сам пакет содержит персональные
        // данные, и журналу они не нужны.
        $this->event($package, 'generated', [
            'payload_sha256' => $package->payload_sha256,
            'schema_version' => $package->schema_version,
            'counts' => $composition->counts,
        ]);

        return $package->fresh();
    }

    private function event(FisOutboundPackage $package, string $type, array $metadata = []): void
    {
        $package->events()->create(['event_type' => $type, 'status' => $package->status, 'metadata' => $metadata, 'user_id' => auth()->id()]);
    }
}
