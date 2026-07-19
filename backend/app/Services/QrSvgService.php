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
        return $this->normalizeScannedTokenDetails($value)['token'];
    }

    /** @return array{token:string,layout_normalized:bool} */
    public function normalizeScannedTokenDetails(string $value): array
    {
        $token = trim($value, " \t\r\n");

        if (str_starts_with($token, 'CP1:')) {
            $token = substr($token, 4);
        }

        $token = trim($token, " \t\r\n");
        $layoutToken = $this->normalizeRussianKeyboardLayout($token);

        if ($layoutToken !== null) {
            return ['token' => $layoutToken, 'layout_normalized' => true];
        }

        return ['token' => $token, 'layout_normalized' => false];
    }

    private function normalizeRussianKeyboardLayout(string $token): ?string
    {
        if (str_starts_with($token, 'CP2:')) {
            return null;
        }

        $map = [
            'й' => 'q', 'ц' => 'w', 'у' => 'e', 'к' => 'r', 'е' => 't', 'н' => 'y', 'г' => 'u', 'ш' => 'i', 'щ' => 'o', 'з' => 'p', 'х' => '[', 'ъ' => ']',
            'ф' => 'a', 'ы' => 's', 'в' => 'd', 'а' => 'f', 'п' => 'g', 'р' => 'h', 'о' => 'j', 'л' => 'k', 'д' => 'l', 'ж' => ';', 'э' => "'",
            'я' => 'z', 'ч' => 'x', 'с' => 'c', 'м' => 'v', 'и' => 'b', 'т' => 'n', 'ь' => 'm', 'б' => ',', 'ю' => '.', 'ё' => '`',
            'Й' => 'Q', 'Ц' => 'W', 'У' => 'E', 'К' => 'R', 'Е' => 'T', 'Н' => 'Y', 'Г' => 'U', 'Ш' => 'I', 'Щ' => 'O', 'З' => 'P', 'Х' => '{', 'Ъ' => '}',
            'Ф' => 'A', 'Ы' => 'S', 'В' => 'D', 'А' => 'F', 'П' => 'G', 'Р' => 'H', 'О' => 'J', 'Л' => 'K', 'Д' => 'L', 'Ж' => ':', 'Э' => '"',
            'Я' => 'Z', 'Ч' => 'X', 'С' => 'C', 'М' => 'V', 'И' => 'B', 'Т' => 'N', 'Ь' => 'M', 'Б' => '<', 'Ю' => '>', 'Ё' => '~',
        ];

        $candidate = strtr($token, $map);

        return preg_match('/^CP2:(?:[A-Za-z0-9_-]{32}|[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/', $candidate)
            ? $candidate
            : null;
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
