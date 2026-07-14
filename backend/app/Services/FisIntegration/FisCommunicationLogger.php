<?php

namespace App\Services\FisIntegration;

use App\Models\FisCommunicationLog;
use Illuminate\Support\Facades\Log;
use Throwable;

class FisCommunicationLogger
{
    private const METADATA_ALLOWLIST = [
        'gateway_version',
        'latency_ms',
        'operation',
        'endpoint_class',
    ];

    public function record(array $entry): void
    {
        try {
            $metadata = array_intersect_key((array) ($entry['metadata'] ?? []), array_flip(self::METADATA_ALLOWLIST));

            FisCommunicationLog::create([
                'occurred_at' => $entry['occurred_at'] ?? now(),
                'request_id' => $entry['request_id'] ?? null,
                'direction' => 'outbound',
                'transport' => 'collegeportal_gateway',
                'method' => (string) ($entry['method'] ?? 'unknown'),
                'duration_ms' => $entry['duration_ms'] ?? null,
                'status' => (string) ($entry['status'] ?? 'failed'),
                'http_code' => $entry['http_code'] ?? null,
                'soap_fault_code' => $this->redact($entry['soap_fault_code'] ?? null),
                'soap_fault_message' => $this->redact($entry['soap_fault_message'] ?? null),
                'error_code' => $this->redact($entry['error_code'] ?? null),
                'metadata' => $metadata ?: null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('FIS communication metadata could not be persisted.', [
                'exception' => $exception::class,
                'method' => $entry['method'] ?? 'unknown',
            ]);
        }
    }

    private function redact(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return preg_replace(
            '/(Authorization|Signature|secret|token|password|login|pass)\s*[:=]\s*[^\s,;]+/i',
            '$1=[redacted]',
            mb_substr((string) $value, 0, 2000)
        );
    }
}
