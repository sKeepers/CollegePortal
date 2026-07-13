<?php

namespace App\Services\FisIntegration;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use Illuminate\Support\Facades\Storage;
use XMLWriter;

class FisPackageBuilder
{
    public function __construct(private readonly FisSpecificationRegistry $registry) {}

    public function generate(FisOutboundPackage $package): FisOutboundPackage
    {
        if ($this->registry->schemaVersion() === 'pending-official-spec') {
            throw new FisIntegrationException('Official FIS schema is not loaded. XML generation is blocked to avoid inventing namespaces or formats.');
        }

        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('FisPackage');
        $writer->writeAttribute('schemaVersion', $package->schema_version);
        $writer->writeElement('PackageType', $package->package_type);
        $writer->writeElement('ExternalCampaignId', (string) $package->external_campaign_id);
        $writer->endElement();
        $writer->endDocument();
        $xml = $writer->outputMemory();

        $path = 'fis/outbound/package-'.$package->id.'/payload.xml';
        Storage::disk('local')->put($path, $xml);
        $package->update(['payload_path' => $path, 'payload_sha256' => hash('sha256', $xml), 'status' => 'generated']);
        $this->event($package, 'generated', ['payload_sha256' => $package->payload_sha256]);
        return $package->fresh();
    }

    private function event(FisOutboundPackage $package, string $type, array $metadata = []): void
    {
        $package->events()->create(['event_type' => $type, 'status' => $package->status, 'metadata' => $metadata, 'user_id' => auth()->id()]);
    }
}
