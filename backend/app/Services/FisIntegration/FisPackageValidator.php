<?php

namespace App\Services\FisIntegration;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use App\Services\FisIntegration\Xml\FisXsdSchema;
use Illuminate\Support\Facades\Storage;
use DOMDocument;

class FisPackageValidator
{
    public function __construct(private readonly FisXsdSchema $schema)
    {
    }

    public function validate(FisOutboundPackage $package): array
    {
        if (! $package->payload_path || ! Storage::disk('local')->exists($package->payload_path)) {
            return $this->fail($package, 'payload_missing', 'XML payload is missing.');
        }
        if (! $this->schema->loaded()) {
            return $this->fail($package, 'xsd_missing', 'Official FIS XSD is not loaded. Validation is blocked.');
        }

        try {
            $schemaSource = $this->schema->source();
        } catch (FisIntegrationException $exception) {
            return $this->fail($package, 'xsd_not_usable', $exception->getMessage());
        }

        $xml = Storage::disk('local')->get($package->payload_path);
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        $valid = $loaded && $dom->schemaValidateSource($schemaSource);
        $errors = collect(libxml_get_errors())->map(fn ($error) => ['level' => $error->level, 'line' => $error->line, 'column' => $error->column, 'code' => $error->code, 'message' => trim($error->message)])->values()->all();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $valid) {
            $package->update(['status' => 'validation_failed', 'last_error_code' => 'xsd_validation_failed', 'last_error_message' => $errors[0]['message'] ?? 'XML does not match official XSD.']);
            $this->event($package, 'validation_failed', ['errors' => $errors]);
            return ['ok' => false, 'errors' => $errors];
        }

        $package->update(['status' => 'validated', 'validated_at' => now(), 'last_error_code' => null, 'last_error_message' => null]);
        $this->event($package, 'validated', [
            'schema_version' => config('fis_api.schema_version'),
            'xsd' => basename((string) $this->schema->path()),
            'xsd_sha256' => $this->schema->fingerprint(),
            'xsd_compatibility_fixes' => $this->schema->compatibilityNotes(),
        ]);
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
