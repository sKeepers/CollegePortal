<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FisConnectionCheckCommand extends Command
{
    protected $signature = 'fis:connection-check {--environment=test : test or production}';
    protected $description = 'Safely check network reachability of the configured FIS endpoint without credentials or payload.';

    public function handle(): int
    {
        $environment = (string) $this->option('environment');
        if ($environment === 'production' && ! config('fis_api.allow_production_send')) {
            $this->warn('Production endpoint check is blocked by default. Use FIS-API-002 controlled activation.');
            return self::FAILURE;
        }
        $endpoint = $environment === 'production' ? config('fis_api.production_endpoint') : config('fis_api.test_endpoint');
        $parts = parse_url((string) $endpoint);
        $host = $parts['host'] ?? null;
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80));
        $start = microtime(true);
        $errorNo = 0;
        $error = '';
        $socket = $host ? @fsockopen($host, $port, $errorNo, $error, (float) config('fis_api.connect_timeout', 5)) : false;
        $latency = (int) round((microtime(true) - $start) * 1000);
        if (is_resource($socket)) {
            fclose($socket);
        }
        $result = ['environment' => $environment, 'endpoint' => $endpoint, 'host' => $host, 'port' => $port, 'scheme' => $parts['scheme'] ?? 'http', 'reachable' => (bool) $socket, 'latency_ms' => $latency, 'error' => $socket ? null : trim($error ?: ('errno '.$errorNo))];
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }
}
