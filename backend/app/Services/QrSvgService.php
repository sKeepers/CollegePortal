<?php

namespace App\Services;

class QrSvgService
{
    private const VERSION = 3;
    private const SIZE = 29;
    private const DATA_CODEWORDS = 44;
    private const ECC_CODEWORDS = 26;
    private const FORMAT_M_MASK_0 = 0x5412;

    /** @var array<int, int> */
    private array $gfExp = [];

    /** @var array<int, int> */
    private array $gfLog = [];

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
        $matrix = $this->buildMatrix($this->qrPayload($token));
        $quiet = 4;
        $module = 10;
        $size = self::SIZE + ($quiet * 2);
        $pixels = $size * $module;
        $rects = [];

        for ($row = 0; $row < self::SIZE; $row++) {
            for ($col = 0; $col < self::SIZE; $col++) {
                if ($matrix[$row][$col]) {
                    $x = ($col + $quiet) * $module;
                    $y = ($row + $quiet) * $module;
                    $rects[] = sprintf('<rect x="%d" y="%d" width="%d" height="%d"/>', $x, $y, $module, $module);
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%1$d" height="%1$d" role="img" aria-label="Digital pass QR"><rect width="100%%" height="100%%" fill="#ffffff"/><g fill="#000000">%2$s</g></svg>',
            $pixels,
            implode('', $rects),
        );
    }

    public function renderPng(string $token): string
    {
        $matrix = $this->buildMatrix($this->qrPayload($token));
        $quiet = 4;
        $module = 10;
        $modules = self::SIZE + ($quiet * 2);
        $pixels = $modules * $module;
        $rows = [];

        for ($y = 0; $y < $pixels; $y++) {
            $moduleY = intdiv($y, $module) - $quiet;
            $row = "\x00";

            for ($x = 0; $x < $pixels; $x++) {
                $moduleX = intdiv($x, $module) - $quiet;
                $black = $moduleY >= 0 && $moduleY < self::SIZE
                    && $moduleX >= 0 && $moduleX < self::SIZE
                    && $matrix[$moduleY][$moduleX];
                $row .= $black ? "\x00\x00\x00" : "\xff\xff\xff";
            }

            $rows[] = $row;
        }

        $header = pack('NNCCCCC', $pixels, $pixels, 8, 2, 0, 0, 0);
        $data = gzcompress(implode('', $rows), 9);

        return "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', $header)
            . $this->pngChunk('IDAT', $data)
            . $this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        $chunk = $type . $data;
        return pack('N', strlen($data)) . $chunk . pack('N', crc32($chunk));
    }

    /** @return array<int, array<int, bool>> */
    private function buildMatrix(string $token): array
    {
        $data = $this->encodeData($token);
        $codewords = array_merge($data, $this->reedSolomon($data, self::ECC_CODEWORDS));
        $bits = [];

        foreach ($codewords as $codeword) {
            for ($bit = 7; $bit >= 0; $bit--) {
                $bits[] = (($codeword >> $bit) & 1) === 1;
            }
        }

        $matrix = array_fill(0, self::SIZE, array_fill(0, self::SIZE, false));
        $reserved = array_fill(0, self::SIZE, array_fill(0, self::SIZE, false));

        $this->addFinder($matrix, $reserved, 0, 0);
        $this->addFinder($matrix, $reserved, self::SIZE - 7, 0);
        $this->addFinder($matrix, $reserved, 0, self::SIZE - 7);
        $this->addAlignment($matrix, $reserved, 22, 22);
        $this->addTiming($matrix, $reserved);
        $this->setFunction($matrix, $reserved, 21, 8, true);
        $this->reserveFormat($reserved);
        $this->placeData($matrix, $reserved, $bits);
        $this->addFormat($matrix, $reserved);

        return $matrix;
    }

    /** @return array<int, int> */
    private function encodeData(string $token): array
    {
        $bytes = array_values(unpack('C*', $token));
        $bits = [false, true, false, false]; // byte mode 0100

        for ($bit = 7; $bit >= 0; $bit--) {
            $bits[] = ((count($bytes) >> $bit) & 1) === 1;
        }

        foreach ($bytes as $byte) {
            for ($bit = 7; $bit >= 0; $bit--) {
                $bits[] = (($byte >> $bit) & 1) === 1;
            }
        }

        $capacityBits = self::DATA_CODEWORDS * 8;
        $terminator = min(4, $capacityBits - count($bits));
        for ($i = 0; $i < $terminator; $i++) {
            $bits[] = false;
        }

        while (count($bits) % 8 !== 0) {
            $bits[] = false;
        }

        $data = [];
        foreach (array_chunk($bits, 8) as $chunk) {
            $value = 0;
            foreach ($chunk as $bit) {
                $value = ($value << 1) | ($bit ? 1 : 0);
            }
            $data[] = $value;
        }

        $pads = [0xec, 0x11];
        $padIndex = 0;
        while (count($data) < self::DATA_CODEWORDS) {
            $data[] = $pads[$padIndex % 2];
            $padIndex++;
        }

        return array_slice($data, 0, self::DATA_CODEWORDS);
    }

    private function addFinder(array &$matrix, array &$reserved, int $x, int $y): void
    {
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $row = $y + $dy;
                $col = $x + $dx;
                if ($row < 0 || $row >= self::SIZE || $col < 0 || $col >= self::SIZE) {
                    continue;
                }

                $black = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6
                    && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
                $this->setFunction($matrix, $reserved, $row, $col, $black);
            }
        }
    }

    private function addAlignment(array &$matrix, array &$reserved, int $centerX, int $centerY): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $black = max(abs($dx), abs($dy)) !== 1;
                $this->setFunction($matrix, $reserved, $centerY + $dy, $centerX + $dx, $black);
            }
        }
    }

    private function addTiming(array &$matrix, array &$reserved): void
    {
        for ($i = 8; $i < self::SIZE - 8; $i++) {
            $black = $i % 2 === 0;
            $this->setFunction($matrix, $reserved, 6, $i, $black);
            $this->setFunction($matrix, $reserved, $i, 6, $black);
        }
    }

    private function reserveFormat(array &$reserved): void
    {
        for ($i = 0; $i <= 8; $i++) {
            if ($i !== 6) {
                $reserved[8][$i] = true;
                $reserved[$i][8] = true;
            }
        }

        for ($i = 0; $i < 8; $i++) {
            $reserved[self::SIZE - 1 - $i][8] = true;
            $reserved[8][self::SIZE - 1 - $i] = true;
        }
    }

    private function addFormat(array &$matrix, array &$reserved): void
    {
        $format = self::FORMAT_M_MASK_0;

        for ($i = 0; $i < 15; $i++) {
            $bit = (($format >> $i) & 1) === 1;

            if ($i < 6) {
                $this->setFunction($matrix, $reserved, 8, $i, $bit);
            } elseif ($i === 6) {
                $this->setFunction($matrix, $reserved, 8, 7, $bit);
            } elseif ($i === 7) {
                $this->setFunction($matrix, $reserved, 8, 8, $bit);
            } elseif ($i === 8) {
                $this->setFunction($matrix, $reserved, 7, 8, $bit);
            } else {
                $this->setFunction($matrix, $reserved, 14 - $i, 8, $bit);
            }

            if ($i < 8) {
                $this->setFunction($matrix, $reserved, self::SIZE - 1 - $i, 8, $bit);
            } else {
                $this->setFunction($matrix, $reserved, 8, self::SIZE - 15 + $i, $bit);
            }
        }
    }

    /** @param array<int, bool> $bits */
    private function placeData(array &$matrix, array &$reserved, array $bits): void
    {
        $bitIndex = 0;
        $row = self::SIZE - 1;
        $direction = -1;

        for ($col = self::SIZE - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }

            while ($row >= 0 && $row < self::SIZE) {
                for ($i = 0; $i < 2; $i++) {
                    $currentCol = $col - $i;
                    if ($reserved[$row][$currentCol]) {
                        continue;
                    }

                    $bit = $bits[$bitIndex] ?? false;
                    $mask = (($row + $currentCol) % 2) === 0;
                    $matrix[$row][$currentCol] = $bit xor $mask;
                    $bitIndex++;
                }
                $row += $direction;
            }

            $row -= $direction;
            $direction = -$direction;
        }
    }

    private function setFunction(array &$matrix, array &$reserved, int $row, int $col, bool $black): void
    {
        if ($row < 0 || $row >= self::SIZE || $col < 0 || $col >= self::SIZE) {
            return;
        }

        $matrix[$row][$col] = $black;
        $reserved[$row][$col] = true;
    }

    /** @param array<int, int> $data @return array<int, int> */
    private function reedSolomon(array $data, int $degree): array
    {
        $this->initGalois();
        $generator = [1];

        for ($i = 0; $i < $degree; $i++) {
            $generator = $this->polyMultiply($generator, [1, $this->gfExp[$i]]);
        }

        $ecc = array_fill(0, $degree, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $ecc[0];
            array_shift($ecc);
            $ecc[] = 0;

            for ($i = 0; $i < $degree; $i++) {
                $ecc[$i] ^= $this->gfMultiply($generator[$i + 1], $factor);
            }
        }

        return $ecc;
    }

    /** @param array<int, int> $left @param array<int, int> $right @return array<int, int> */
    private function polyMultiply(array $left, array $right): array
    {
        $result = array_fill(0, count($left) + count($right) - 1, 0);

        foreach ($left as $i => $leftValue) {
            foreach ($right as $j => $rightValue) {
                $result[$i + $j] ^= $this->gfMultiply($leftValue, $rightValue);
            }
        }

        return $result;
    }

    private function initGalois(): void
    {
        if ($this->gfExp !== []) {
            return;
        }

        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $this->gfExp[$i] = $x;
            $this->gfLog[$x] = $i;
            $x <<= 1;
            if (($x & 0x100) !== 0) {
                $x ^= 0x11d;
            }
        }

        for ($i = 255; $i < 512; $i++) {
            $this->gfExp[$i] = $this->gfExp[$i - 255];
        }
    }

    private function gfMultiply(int $left, int $right): int
    {
        if ($left === 0 || $right === 0) {
            return 0;
        }

        return $this->gfExp[$this->gfLog[$left] + $this->gfLog[$right]];
    }
}
