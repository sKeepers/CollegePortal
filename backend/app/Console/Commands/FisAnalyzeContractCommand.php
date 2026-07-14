<?php

namespace App\Console\Commands;

use App\Services\FisIntegration\FisSpecificationRegistry;
use Illuminate\Console\Command;

class FisAnalyzeContractCommand extends Command
{
    protected $signature = 'fis:analyze-contract
        {--wsdl= : Explicit local WSDL path}
        {--xsd= : Explicit local XSD path}
        {--disco= : Explicit local DISCO path}
        {--write-doc= : Write Markdown analysis to this path}';
    protected $description = 'Parse the locally loaded official FIS WSDL/XSD/DISCO without network calls.';

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

        return ($analysis['status'] ?? 'missing') === 'loaded' ? self::SUCCESS : self::FAILURE;
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
            '# Анализ WSDL ФИС ГИА и Приема',
            '',
            '> Сформировано автоматически командой `php artisan fis:analyze-contract` с явно указанными локальными путями к доступным официальным артефактам.',
            '',
            'Дата анализа: '.now()->format('d.m.Y H:i:s T'),
            '',
            '## Stop-gate',
            '',
        ];

        if (($analysis['status'] ?? null) !== 'loaded') {
            $lines[] = 'Официальный WSDL не загружен. SOAP version, binding, actions, методы, request/response и faults не подтверждены. Import и read-only SOAP-вызовы запрещены.';
        } else {
            $lines[] = 'WSDL загружен и разобран локально с запретом сетевых XML-ресурсов (`LIBXML_NONET`).';
        }

        $lines = array_merge($lines, [
            '',
            '## Файлы',
            '',
            '| Артефакт | Статус | SHA-256 |',
            '|---|---|---|',
            '| WSDL | '.($analysis['wsdl']['status'] ?? 'missing').' | '.($analysis['wsdl']['sha256'] ?? '—').' |',
            '| XSD | '.($analysis['xsd']['status'] ?? 'missing').' | '.($analysis['xsd']['sha256'] ?? '—').' |',
            '| DISCO | '.($analysis['disco']['status'] ?? 'missing').' | '.($analysis['disco']['sha256'] ?? '—').' |',
            '',
            '## Контракт',
            '',
            '- Target namespace: `'.($analysis['target_namespace'] ?? 'не определен').'`',
            '- SOAP versions: `'.(implode(', ', $analysis['soap_versions'] ?? []) ?: 'не определены').'`',
            '- Authentication: `'.($analysis['authentication'] ?? 'unknown').'`',
            '- Bindings: '.count($analysis['bindings'] ?? []),
            '- Services: '.count($analysis['services'] ?? []),
            '- Operations: '.count($analysis['operations'] ?? []),
            '',
            '## Методы',
            '',
            '| Method | Request | Response | SOAP Action | Faults |',
            '|---|---|---|---|---|',
        ]);

        foreach ($analysis['operations'] ?? [] as $operation) {
            $actions = collect($operation['bindings'] ?? [])->pluck('soap_action')->filter()->implode(', ');
            $faults = collect($operation['faults'] ?? [])->pluck('name')->filter()->implode(', ');
            $lines[] = '| '.$operation['name'].' | '.($operation['input_message'] ?: '—').' | '.($operation['output_message'] ?: '—').' | '.($actions ?: '—').' | '.($faults ?: '—').' |';
        }

        if (! ($analysis['operations'] ?? [])) {
            $lines[] = '| — | — | — | — | Ожидается официальный WSDL |';
        }

        $lines[] = '';
        $lines[] = '## Подтвержденная XSD';
        $lines[] = '';
        $lines[] = '- Target namespace: `'.($analysis['xsd']['target_namespace'] ?? 'отсутствует').'`';
        $lines[] = '- Root elements: `'.(implode('`, `', $analysis['xsd']['root_elements'] ?? []) ?: 'не определены').'`';
        $lines[] = '- Payload AuthData elements: `'.(implode('`, `', $analysis['xsd']['authentication_elements'] ?? []) ?: 'не определены').'`';
        $lines[] = '';
        $lines[] = 'XSD подтверждает payload-level `AuthData`, но не определяет HTTP/SOAP transport authentication. SOAP envelope, action, binding и transport берутся только из официального WSDL/DISCO.';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
