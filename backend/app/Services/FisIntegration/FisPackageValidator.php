<?php

namespace App\Services\FisIntegration;

use App\Models\FisOutboundPackage;
use Illuminate\Support\Facades\Storage;
use DOMDocument;

class FisPackageValidator
{
    public function __construct(private readonly FisSpecificationRegistry $registry) {}

    public function validate(FisOutboundPackage $package): array
    {
        if (! $package->payload_path || ! Storage::disk('local')->exists($package->payload_path)) {
            return $this->fail($package, 'payload_missing', 'XML payload is missing.');
        }
        $xsd = $this->registry->xsdPath();
        if (! $xsd) {
            return $this->fail($package, 'xsd_missing', 'Official FIS XSD is not loaded. Validation is blocked.');
        }

        $xml = Storage::disk('local')->get($package->payload_path);
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        $valid = $loaded && $dom->schemaValidate($xsd);
        $errors = collect(libxml_get_errors())->map(fn ($error) => ['level' => $error->level, 'line' => $error->line, 'column' => $error->column, 'code' => $error->code, 'message' => trim($error->message)])->values()->all();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $valid) {
            $package->update(['status' => 'validation_failed', 'last_error_code' => 'xsd_validation_failed', 'last_error_message' => $errors[0]['message'] ?? 'XML does not match official XSD.']);
            $this->event($package, 'validation_failed', ['errors' => $errors]);
            return ['ok' => false, 'errors' => $errors];
        }

        $package->update(['status' => 'validated', 'validated_at' => now(), 'last_error_code' => null, 'last_error_message' => null]);
        $this->event($package, 'validated', ['xsd' => basename($xsd)]);
        return ['ok' => true, 'errors' => []];
    }

    private function fail(FisOutboundPackage $package, string $code, string $message): array
    {
        $package->update(['status' => 'validation_failed', 'last_error_code' => $code, 'last_error_message' => $message]);
        $this->event($package, 'validation_failed', ['code' => $code, 'message' => $message]);
        return ['ok' => false, 'errors' => [['code' => $code, 'message' => $message]]];
    }

    private function event(FisOutboundPackage $package, string $type, array $metadata = []): void
    {
        $package->events()->create(['event_type' => $type, 'status' => $package->status, 'metadata' => $metadata, 'user_id' => auth()->id()]);
    }
}
