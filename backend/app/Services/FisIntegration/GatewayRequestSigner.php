<?php

namespace App\Services\FisIntegration;

use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use Illuminate\Support\Str;

class GatewayRequestSigner
{
    public const HEADER_REQUEST_ID = 'X-Gateway-Request-Id';
    public const HEADER_TIMESTAMP = 'X-Gateway-Timestamp';
    public const HEADER_NONCE = 'X-Gateway-Nonce';
    public const HEADER_BODY_SHA256 = 'X-Gateway-Body-SHA256';
    public const HEADER_SIGNATURE = 'X-Gateway-Signature';

    public function headers(string $method, string $path, string $body): array
    {
        $secret = (string) config('fis_api.gateway_shared_secret');
        if ($secret === '') throw new FisIntegrationException('CollegePortal Gateway shared secret is not configured.');
        $requestId = (string) Str::uuid();
        $timestamp = now('UTC')->format('Y-m-d\TH:i:s\Z');
        $nonce = (string) Str::uuid();
        $bodyHash = hash('sha256', $body);
        $signature = base64_encode(hash_hmac('sha256', $this->canonicalString($method, $path, $timestamp, $nonce, $bodyHash), $secret, true));
        return [
            self::HEADER_REQUEST_ID => $requestId,
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_BODY_SHA256 => $bodyHash,
            self::HEADER_SIGNATURE => $signature,
            'X-FIS-Request-Id' => $requestId,
            'X-FIS-Timestamp' => $timestamp,
            'X-FIS-Nonce' => $nonce,
            'X-FIS-Body-SHA256' => $bodyHash,
            'X-FIS-Signature' => $signature,
        ];
    }

    public function canonicalString(string $method, string $path, string $timestamp, string $nonce, string $bodyHash): string
    {
        return strtoupper($method)."\n".$path."\n".$timestamp."\n".$nonce."\n".$bodyHash;
    }
}
