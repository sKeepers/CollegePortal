<?php

namespace App\Services;

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
        $token = trim($value, " \t\r\n");

        if (str_starts_with($token, 'CP1:')) {
            $token = substr($token, 4);
        }

        return trim($token, " \t\r\n");
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
}
