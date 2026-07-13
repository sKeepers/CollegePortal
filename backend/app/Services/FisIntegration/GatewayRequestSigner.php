<?php

namespace App\Services\FisIntegration;

use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use Illuminate\Support\Str;

class GatewayRequestSigner
{
    public const HEADER_REQUEST_ID = 'X-FIS-Request-Id';
    public const HEADER_TIMESTAMP = 'X-FIS-Timestamp';
    public const HEADER_NONCE = 'X-FIS-Nonce';
    public const HEADER_BODY_SHA256 = 'X-FIS-Body-SHA256';
    public const HEADER_SIGNATURE = 'X-FIS-Signature';

    public function headers(string $method, string $path, string $body): array
    {
        $secret = (string) config('fis_api.gateway_shared_secret');
        if ($secret === '') {
            throw new FisIntegrationException('FIS Gateway shared secret is not configured.');
        }

        $timestamp = now('UTC')->format('Y-m-d\TH:i:s\Z');
        $nonce = (string) Str::uuid();
        $bodyHash = hash('sha256', $body);
        $canonical = $this->canonicalString($method, $path, $timestamp, $nonce, $bodyHash);

        return [
            self::HEADER_REQUEST_ID => (string) Str::uuid(),
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_BODY_SHA256 => $bodyHash,
            self::HEADER_SIGNATURE => base64_encode(hash_hmac('sha256', $canonical, $secret, true)),
        ];
    }

    public function canonicalString(string $method, string $path, string $timestamp, string $nonce, string $bodyHash): string
    {
        return strtoupper($method)."\n".$path."\n".$timestamp."\n".$nonce."\n".$bodyHash;
    }
}
