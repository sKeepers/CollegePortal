<?php

namespace App\Services\Documents;

class DocumentPdfService
{
    public function convertDocxToPdf(string $docxPath, string $targetPdfPath): array
    {
        $binary = trim((string) config('documents.pdf_converter', ''));
        if ($binary === '') {
            $binary = trim((string) shell_exec('command -v libreoffice || command -v soffice'));
        }

        if ($binary === '') {
            return ['available' => false, 'path' => null, 'message' => 'LibreOffice headless недоступен. DOCX сформирован, PDF не создан.'];
        }

        $outDir = dirname($targetPdfPath);
        if (! is_dir($outDir)) {
            mkdir($outDir, 0775, true);
        }

        $command = escapeshellcmd($binary).' --headless --convert-to pdf --outdir '.escapeshellarg($outDir).' '.escapeshellarg($docxPath).' 2>&1';
        exec($command, $output, $code);
        $converted = $outDir.'/'.pathinfo($docxPath, PATHINFO_FILENAME).'.pdf';

        if ($code !== 0 || ! is_file($converted)) {
            return ['available' => false, 'path' => null, 'message' => 'PDF converter вернул ошибку: '.implode("\n", $output)];
        }

        if ($converted !== $targetPdfPath) {
            rename($converted, $targetPdfPath);
        }

        return ['available' => true, 'path' => $targetPdfPath, 'message' => 'PDF сформирован.'];
    }
}
