<?php

namespace App\Services\FisIntegration;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\Dto\FisTransportResult;

class GatewayFisTransport implements FisTransportInterface
{
    public function __construct(private ?CollegePortalGatewayClient $client = null)
    {
        $this->client ??= new CollegePortalGatewayClient();
    }

    public function send(FisOutboundPackage $package, string $xml): FisTransportResult
    {
        $data = $this->client->post('/adapters/fis/test/import', [
            'request_id' => $package->request_id,
            'package_hash' => hash('sha256', $xml),
            'package_type' => $package->package_type,
            'schema_version' => $package->schema_version,
            'payload' => base64_encode($xml),
        ]);
        return new FisTransportResult((bool) ($data['ok'] ?? false), $data['package_id'] ?? null, $data['status'] ?? null, $data['error_code'] ?? null, $data['message'] ?? null, ['transport' => 'collegeportal_gateway', 'gateway_version' => $data['gateway_version'] ?? null]);
    }

    public function status(FisOutboundPackage $package): FisTransportResult
    {
        $data = $this->client->post('/adapters/fis/test/import-result', ['request_id' => $package->request_id, 'package_id' => $package->package_id]);
        return new FisTransportResult((bool) ($data['ok'] ?? false), $package->package_id, $data['status'] ?? null, $data['error_code'] ?? null, $data['message'] ?? null, ['transport' => 'collegeportal_gateway', 'gateway_version' => $data['gateway_version'] ?? null]);
    }

    public function health(): array { return $this->client->health(); }
    public function version(): array { return $this->client->version(); }
    public function capabilities(): array { return $this->client->capabilities(); }
    public function listAdapters(): array { return $this->client->listAdapters(); }
    public function adapterHealth(): array { return $this->client->adapterHealth('fis'); }
    public function diagnostics(): array { return $this->client->runDiagnostics(); }
    public function latestDiagnostics(): array { return $this->client->latestDiagnostics(); }
    public function zkspdCheck(): array { return $this->client->post('/adapters/fis/zkspd/check', []); }
    public function dictionariesList(): array { return $this->client->post('/adapters/fis/test/dictionaries/list', []); }
    public function dictionaryDetails(?string $code = null): array { return $this->client->post('/adapters/fis/test/dictionaries/details', ['code' => $code]); }
    public function institutionInfo(): array { return $this->client->post('/adapters/fis/test/institution/info', []); }
    public function testCheckApplication(array $payload = []): array { return $this->client->post('/adapters/fis/test/check-application', $payload); }
    public function validateDisabled(): array { return $this->client->post('/adapters/fis/test/validate', []); }
    public function importDisabled(): array { return $this->client->post('/adapters/fis/test/import', []); }
}
