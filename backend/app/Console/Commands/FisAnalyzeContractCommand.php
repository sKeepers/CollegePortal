<?php

namespace App\Console\Commands;

use App\Services\FisIntegration\FisSpecificationRegistry;
use Illuminate\Console\Command;

class FisAnalyzeContractCommand extends Command
{
    protected $signature = 'fis:analyze-contract
        {--wsdl= : Optional local WSDL path for historical metadata only}
        {--xsd= : Explicit local XSD path}
        {--disco= : Optional local DISCO path for historical metadata only}
        {--write-doc= : Write Markdown analysis to this path}';
    protected $description = 'Parse locally loaded official FIS XML-over-HTTP XSD metadata without network calls.';

    public function handle(FisSpecificationRegistry $registry): int
    {
        foreach (['wsdl', 'xsd', 'disco'] as $artifact) {
            if ($path = $this->option($artifact)) {
                config(["fis_api.{$artifact}_path" => $this->absolutePath((string) $path)]);
            }
        }

        $analysis = $registry->analysis();
        $this->line(json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        if ($path = $this->option('write-doc')) {
            $absolutePath = $this->absolutePath((string) $path);
            if (! is_dir(dirname($absolutePath))) {
                mkdir(dirname($absolutePath), 0775, true);
            }
            file_put_contents($absolutePath, $this->markdown($analysis));
            $this->info('Analysis written to '.$absolutePath);
        }

        return in_array(($analysis['status'] ?? 'missing'), ['loaded', 'xsd_loaded'], true) ? self::SUCCESS : self::FAILURE;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : base_path('../'.ltrim($path, '/'));
    }

    private function markdown(array $analysis): string
    {
        $lines = [
            '# Анализ XML-over-HTTP контракта ФИС ГИА и Приема',
            '',
            '> Сформировано автоматически командой `php artisan fis:analyze-contract` с локальными официальными артефактами. Официальная модель передачи: HTTP POST с XML body, без SOAP envelope/SOAPAction.',
            '',
            'Дата анализа: '.now()->format('d.m.Y H:i:s T'),
            '',
            '## Stop-gate',
            '',
        ];

        if (($analysis['xsd']['status'] ?? null) !== 'loaded') {
            $lines[] = 'Официальная XSD не загружена. XML root, namespaces, request/response, payload authentication и read-only метод не подтверждены. Live TEST вызов запрещен.';
        } else {
            $lines[] = 'XSD загружена и разобрана локально с запретом сетевых XML-ресурсов (`LIBXML_NONET`). WSDL/DISCO не являются обязательными для официальной XML-over-HTTP модели.';
        }

        $lines = array_merge($lines, [
            '',
            '## Файлы',
            '',
            '| Артефакт | Статус | SHA-256 |',
            '|---|---|---|',
            '| XSD | '.($analysis['xsd']['status'] ?? 'missing').' | '.($analysis['xsd']['sha256'] ?? '—').' |',
            '| WSDL | '.($analysis['wsdl']['status'] ?? 'missing').' | '.($analysis['wsdl']['sha256'] ?? '—').' |',
            '| DISCO | '.($analysis['disco']['status'] ?? 'missing').' | '.($analysis['disco']['sha256'] ?? '—').' |',
            '',
            '## Контракт',
            '',
            '- Protocol: `xml_over_http`',
            '- HTTP method: `POST`',
            '- SOAP: `not used`',
            '- XSD target namespace: `'.($analysis['xsd']['target_namespace'] ?? 'не определен').'`',
            '- XSD root elements: `'.(implode('`, `', $analysis['xsd']['root_elements'] ?? []) ?: 'не определены').'`',
            '- Payload AuthData elements: `'.(implode('`, `', $analysis['xsd']['authentication_elements'] ?? []) ?: 'не определены').'`',
            '',
            '## Read-only метод',
            '',
            'До подтверждения официальной XSD/инструкции метод `GetTestDictionariesList` считается кандидатом, а не разрешением на live-вызов.',
            '',
            '## Legacy SOAP metadata',
            '',
            'Если WSDL/DISCO загружены как исторические артефакты, они разбираются только для инвентаризации. SOAP binding, service/port и SOAPAction не используются runtime-транспортом CollegePortal.',
        ]);

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
