<?php

namespace App\Services;

use App\Models\DigitalIdentity;
use Illuminate\Support\Carbon;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\Result\ResultInterface;
use Endroid\QrCode\Writer\SvgWriter;
use Endroid\QrCode\Writer\WriterInterface;

class QrSvgService
{
    public const QR_CONTENT_SIZE_PX = 290;
    public const QUIET_ZONE_PX = 40;
    public const DYNAMIC_PREFIX = 'CP2';
    public const DYNAMIC_TTL_SECONDS = 30;
    private const DYNAMIC_SIGNATURE_LENGTH = 16;

    public function qrPayload(string $token): string
    {
        $payload = trim($token, " \t\r\n");

        if (! preg_match('/^[\x21-\x7E]+$/', $payload)) {
            throw new \InvalidArgumentException('QR token must be printable ASCII without spaces.');
        }

        return $payload;
    }

    public function normalizeScannedToken(string $value): string
    {
        return trim($value, " \t\r\n");
    }

    public function isDynamicPayload(string $value): bool
    {
        return str_starts_with($this->normalizeScannedToken($value), self::DYNAMIC_PREFIX.':');
    }

    public function dynamicPayload(DigitalIdentity $identity, int $ttlSeconds = self::DYNAMIC_TTL_SECONDS): array
    {
        $expiresAt = now()->addSeconds(max(5, $ttlSeconds));
        $expiresToken = strtoupper(base_convert((string) $expiresAt->timestamp, 10, 36));
        $signature = $this->dynamicSignature($identity, $expiresToken);

        return [
            'payload' => self::DYNAMIC_PREFIX.":{$expiresToken}:{$signature}",
            'expires_at' => $expiresAt,
            'ttl_seconds' => $ttlSeconds,
        ];
    }

    public function resolveScannedIdentity(string $value): ?DigitalIdentity
    {
        $token = $this->normalizeScannedToken($value);

        if (! str_starts_with($token, self::DYNAMIC_PREFIX.':')) {
            return null;
        }

        if (! preg_match('/^'.self::DYNAMIC_PREFIX.':([0-9A-Z]{1,10}):([a-f0-9]{'.self::DYNAMIC_SIGNATURE_LENGTH.'})$/i', $token, $matches)) {
            return null;
        }

        $expiresToken = strtoupper($matches[1]);
        $signature = strtolower($matches[2]);
        $expiresAt = Carbon::createFromTimestamp((int) base_convert(strtolower($expiresToken), 36, 10));

        if ($expiresAt->isPast() || $expiresAt->greaterThan(now()->addMinutes(2))) {
            return null;
        }

        return DigitalIdentity::query()
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->get()
            ->first(fn (DigitalIdentity $identity): bool => hash_equals($this->dynamicSignature($identity, $expiresToken), $signature));
    }

    public function renderSvg(string $token): string
    {
        $result = $this->buildQr(new SvgWriter(), $this->qrPayload($token), [
            SvgWriter::WRITER_OPTION_COMPACT => true,
            SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
        ]);

        $svg = $result->getString();
        $svg = preg_replace('/<svg\b/', '<svg role="img" aria-label="Digital pass QR" shape-rendering="crispEdges"', $svg, 1) ?? $svg;

        return $svg;
    }

    public function renderPng(string $token): string
    {
        return $this->buildQr(new PngWriter(), $this->qrPayload($token), [
            PngWriter::WRITER_OPTION_COMPRESSION_LEVEL => 9,
        ])->getString();
    }

    /** @param array<string, mixed> $writerOptions */
    private function buildQr(WriterInterface $writer, string $payload, array $writerOptions = []): ResultInterface
    {
        return (new Builder(
            writer: $writer,
            writerOptions: $writerOptions,
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: self::QR_CONTENT_SIZE_PX,
            margin: self::QUIET_ZONE_PX,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ))->build();
    }

    private function dynamicSignature(DigitalIdentity $identity, string $expiresToken): string
    {
        $message = implode('|', [
            self::DYNAMIC_PREFIX,
            $identity->id,
            $identity->entity_type,
            $identity->entity_id,
            $expiresToken,
        ]);

        return substr(hash_hmac('sha256', $message, $this->dynamicSecret($identity)), 0, self::DYNAMIC_SIGNATURE_LENGTH);
    }

    private function dynamicSecret(DigitalIdentity $identity): string
    {
        return implode('|', [
            (string) config('app.key'),
            $identity->token,
            $identity->issued_at?->timestamp ?? '',
        ]);
    }
}
