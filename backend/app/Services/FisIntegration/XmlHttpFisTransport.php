<?php

namespace App\Services\FisIntegration;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\Dto\FisTransportResult;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use DOMDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class XmlHttpFisTransport implements FisTransportInterface
{
    private const ALLOWED_TEST_HOST = '10.0.3.1';
    private const ALLOWED_TEST_PORT = 8383;
    private const ALLOWED_TEST_PATH = '/api/import/importservice.svc';

    public function __construct(private ?FisCommunicationLogger $communicationLogger = null)
    {
        $this->communicationLogger ??= new FisCommunicationLogger();
    }

    public function send(FisOutboundPackage $package, string $xml): FisTransportResult
    {
        if ($package->environment === 'production') {
            throw new FisIntegrationException('Production FIS endpoint is blocked. Use only TEST :8383.');
        }

        if (! (bool) config('fis_api.enable_mutating_operations', false)) {
            throw new FisIntegrationException('FIS Import is disabled for GIA-003. Only confirmed read-only TEST calls may be enabled after official XSD/auth validation.');
        }

        return $this->postXml('Import', $xml, $package->request_id ?: (string) Str::uuid());
    }

    public function status(FisOutboundPackage $package): FisTransportResult
    {
        throw new FisIntegrationException('FIS package status is blocked until the official XML-over-HTTP status request is confirmed by XSD/specification.');
    }

    public function postXml(string $operation, string $xml, ?string $requestId = null): FisTransportResult
    {
        $endpoint = $this->testEndpoint();
        $this->assertPlainXml($xml);
        $requestId ??= (string) Str::uuid();
        $started = microtime(true);

        try {
            $response = Http::timeout((int) config('fis_api.request_timeout', 30))
                ->connectTimeout((int) config('fis_api.connect_timeout', 5))
                ->withHeaders([
                    'X-CollegePortal-Request-Id' => $requestId,
                    'Accept' => 'application/xml, text/xml;q=0.9, */*;q=0.1',
                ])
                ->withBody($xml, (string) config('fis_api.content_type', 'application/xml; charset=UTF-8'))
                ->post($endpoint);
        } catch (ConnectionException $exception) {
            $this->record($requestId, $operation, $started, null, 'failed', 'connection_failed');
            throw new FisIntegrationException('FIS TEST XML-over-HTTP connection failed: '.$this->redact($exception->getMessage()));
        }

        $summary = $this->parseResponse((string) $response->body());
        $ok = $response->successful() && ! ($summary['fault'] ?? false) && blank($summary['error_code'] ?? null);
        $this->record(
            $requestId,
            $operation,
            $started,
            $response->status(),
            $ok ? 'ok' : 'failed',
            $summary['error_code'] ?? null,
            $summary['fault_code'] ?? null,
            $summary['fault_message'] ?? null,
        );

        return new FisTransportResult(
            ok: $ok,
            packageId: $summary['package_id'] ?? null,
            status: $summary['status'] ?? ($ok ? 'received' : 'failed'),
            errorCode: $summary['error_code'] ?? ($ok ? null : 'fis_xml_http_failed'),
            errorMessage: $ok ? null : ($summary['message'] ?? 'FIS XML-over-HTTP request failed.'),
            metadata: [
                'transport' => 'fis_xml_http',
                'protocol' => 'xml_over_http',
                'operation' => $operation,
                'endpoint_class' => 'test',
                'http_code' => $response->status(),
            ],
        );
    }

    private function testEndpoint(): string
    {
        $endpoint = (string) config('fis_api.test_endpoint');
        $parts = parse_url($endpoint);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'http'
            || ! hash_equals(self::ALLOWED_TEST_HOST, strtolower((string) ($parts['host'] ?? '')))
            || (int) ($parts['port'] ?? 80) !== self::ALLOWED_TEST_PORT
            || strtolower((string) ($parts['path'] ?? '')) !== self::ALLOWED_TEST_PATH) {
            throw new FisIntegrationException('Configured FIS TEST endpoint is outside the fixed allowlist 10.0.3.1:8383/api/import/importservice.svc.');
        }

        return $endpoint;
    }

    private function assertPlainXml(string $xml): void
    {
        if (stripos($xml, '<soap:Envelope') !== false || stripos($xml, '<s:Envelope') !== false || stripos($xml, '<Envelope') !== false) {
            throw new FisIntegrationException('SOAP Envelope is forbidden. Official FIS protocol is XML-over-HTTP.');
        }
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new FisIntegrationException('DOCTYPE is forbidden in FIS XML to prevent XXE.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            $message = trim($errors[0]->message ?? 'XML is invalid.');
            throw new FisIntegrationException('FIS request XML is invalid: '.$message);
        }
    }

    private function parseResponse(string $xml): array
    {
        if (trim($xml) === '') {
            return ['message' => 'Empty FIS response.', 'error_code' => 'empty_response'];
        }
        if (stripos($xml, '<!DOCTYPE') !== false) {
            return ['message' => 'DOCTYPE is forbidden in FIS response XML.', 'error_code' => 'doctype_forbidden'];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return ['message' => 'Invalid XML response.', 'error_code' => 'invalid_xml_response'];
        }

        return [
            'package_id' => $this->firstText($dom, ['PackageID', 'PackageId', 'IdPackage']),
            'status' => $this->firstText($dom, ['Status', 'State', 'Result']),
            'error_code' => $this->firstText($dom, ['ErrorCode', 'Code']),
            'message' => $this->firstText($dom, ['Message', 'ErrorMessage', 'Description']),
            'fault' => $this->firstText($dom, ['Fault', 'FaultCode']) !== null,
            'fault_code' => $this->firstText($dom, ['FaultCode', 'ErrorCode']),
            'fault_message' => $this->firstText($dom, ['FaultString', 'FaultMessage', 'ErrorMessage']),
        ];
    }

    private function firstText(DOMDocument $dom, array $names): ?string
    {
        $lookup = array_fill_keys($names, true);
        foreach ($dom->getElementsByTagName('*') as $node) {
            if (! isset($lookup[$node->localName])) {
                continue;
            }
            $value = trim((string) $node->textContent);
            if ($value !== '') {
                return mb_substr($value, 0, 500);
            }
        }

        return null;
    }

    private function record(string $requestId, string $operation, float $started, ?int $httpCode, string $status, ?string $errorCode = null, ?string $faultCode = null, ?string $faultMessage = null): void
    {
        $this->communicationLogger->record([
            'request_id' => $requestId,
            'method' => 'POST XML '.$operation,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'status' => $status,
            'http_code' => $httpCode,
            'xml_fault_code' => $faultCode,
            'xml_fault_message' => $faultMessage,
            'error_code' => $errorCode,
            'transport' => 'fis_xml_http',
            'metadata' => [
                'operation' => $operation,
                'endpoint_class' => 'test',
                'protocol' => 'xml_over_http',
            ],
        ]);
    }

    private function redact(string $message): string
    {
        return preg_replace('/(Authorization|Signature|secret|token|password|login|pass)[^\s,;]*/i', '$1=[redacted]', $message) ?? $message;
    }
}
