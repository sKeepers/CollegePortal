<?php

namespace App\Services\FisIntegration;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FisInfrastructureProbe
{
    private Closure $tcpConnector;

    public function __construct(
        ?Closure $tcpConnector = null,
        private ?FisCommunicationLogger $communicationLogger = null,
    ) {
        $this->tcpConnector = $tcpConnector ?? static function (string $host, int $port, float $timeout): array {
            $started = microtime(true);
            $errorNo = 0;
            $error = '';
            $socket = @stream_socket_client("tcp://{$host}:{$port}", $errorNo, $error, $timeout, STREAM_CLIENT_CONNECT);
            $latency = (int) round((microtime(true) - $started) * 1000);

            $connected = is_resource($socket);
            if ($connected) {
                fclose($socket);
            }

            return [
                'connected' => $connected,
                'errno' => $errorNo,
                'error' => $error,
                'latency_ms' => $latency,
            ];
        };
        $this->communicationLogger ??= new FisCommunicationLogger();
    }

    public function snapshot(bool $run = false): array
    {
        $gateway = $this->endpoint((string) config('fis_api.gateway_diagnostics_url'));
        $test = $this->endpoint((string) config('fis_api.test_endpoint'));
        $checks = [
            'portal' => $this->state('ok', 'CollegePortal backend generated this diagnostics snapshot.'),
            'gateway_target' => $gateway
                ? $this->state('configured', 'Gateway diagnostics target is configured.', $this->publicEndpointDetails($gateway))
                : $this->state('blocked', 'Gateway diagnostics target is not configured.'),
            'gateway_host' => $this->state('not_checked', 'Run diagnostics to observe the Gateway host.'),
            'gateway_port' => $this->state('not_checked', 'Run diagnostics to test TCP port 8099.'),
            'gateway_service' => $this->state('unknown', 'Windows service state requires Gateway /health or a local Windows evidence bundle.'),
            'gateway_health' => $this->state('not_checked', 'Gateway /health has not been requested.'),
            'gateway_version' => $this->state('not_checked', 'Gateway /version has not been requested.'),
            'gateway_adapters' => $this->state('not_checked', 'Gateway /adapters has not been requested.'),
            'gateway_adapter' => $this->state('blocked', 'Protected FIS adapter health requires an available Gateway and configured HMAC.'),
            'zkspd' => $this->state('blocked', 'ViPNet/ZKSPD state is known only from the Gateway FIS adapter or operator evidence.'),
            'fis_test_direct' => $this->state('not_checked', 'Direct DEV-to-TEST TCP reachability has not been checked.'),
        ];
        $blockers = [];

        if (! $gateway) {
            $blockers[] = 'gateway_diagnostics_target_missing';
        }

        if (! $run) {
            return ['checks' => $checks, 'blockers' => $blockers];
        }

        $gatewayTcp = $gateway ? $this->tcp($gateway) : null;
        if ($gatewayTcp) {
            $checks['gateway_port'] = $gatewayTcp;
            $checks['gateway_host'] = $this->hostState($gatewayTcp);

            if (($gatewayTcp['status'] ?? null) === 'ok') {
                $checks['gateway_health'] = $this->get($gateway, '/health');
                $checks['gateway_version'] = $this->get($gateway, '/version');
                $checks['gateway_adapters'] = $this->get($gateway, '/adapters');
                $checks['gateway_service'] = ($checks['gateway_health']['status'] ?? null) === 'ok'
                    ? $this->state('running', 'Gateway /health returned a successful response.')
                    : $this->state('unknown', 'TCP is open, but /health did not confirm a running Gateway service.');
            } else {
                $code = $gatewayTcp['details']['error_code'] ?? 'gateway_tcp_failed';
                $checks['gateway_health'] = $this->state('blocked', 'Gateway /health was not requested because TCP 8099 is unavailable.');
                $checks['gateway_version'] = $this->state('blocked', 'Gateway /version was not requested because TCP 8099 is unavailable.');
                $checks['gateway_adapters'] = $this->state('blocked', 'Gateway /adapters was not requested because TCP 8099 is unavailable.');
                $checks['gateway_service'] = $this->state('unknown', 'Remote TCP evidence cannot distinguish a stopped service, bind failure, firewall reject, or process crash.', ['evidence' => $code]);
                $blockers[] = $code;
            }
        }

        if ($test) {
            $checks['fis_test_direct'] = $this->tcp($test);
            if (($checks['fis_test_direct']['status'] ?? null) !== 'ok') {
                $blockers[] = $checks['fis_test_direct']['details']['error_code'] ?? 'fis_test_tcp_failed';
            }
        } else {
            $checks['fis_test_direct'] = $this->state('blocked', 'FIS TEST endpoint is not configured.');
            $blockers[] = 'fis_test_endpoint_missing';
        }

        return ['checks' => $checks, 'blockers' => array_values(array_unique($blockers))];
    }

    private function tcp(array $endpoint): array
    {
        $result = ($this->tcpConnector)($endpoint['host'], $endpoint['port'], (float) config('fis_api.gateway_connect_timeout', 5));
        $connected = (bool) ($result['connected'] ?? false);
        $errorCode = $this->tcpErrorCode((int) ($result['errno'] ?? 0), (string) ($result['error'] ?? ''));

        return $this->state(
            $connected ? 'ok' : 'failed',
            $connected ? 'TCP connection succeeded.' : $this->tcpFailureMessage($errorCode),
            [
                'host' => $endpoint['host'],
                'port' => $endpoint['port'],
                'latency_ms' => (int) ($result['latency_ms'] ?? 0),
                'error_code' => $connected ? null : $errorCode,
            ],
        );
    }

    private function get(array $endpoint, string $path): array
    {
        $requestId = (string) Str::uuid();
        $started = microtime(true);
        $url = rtrim($endpoint['base_url'], '/').$path;

        try {
            $response = Http::acceptJson()
                ->withoutRedirecting()
                ->connectTimeout((int) config('fis_api.gateway_connect_timeout', 5))
                ->timeout((int) config('fis_api.gateway_request_timeout', 10))
                ->withHeaders([GatewayRequestSigner::HEADER_REQUEST_ID => $requestId])
                ->get($url);
            $duration = (int) round((microtime(true) - $started) * 1000);
            $data = $response->json();
            $ok = $response->successful() && is_array($data) && ($data['ok'] ?? true) !== false;
            $details = ['http_code' => $response->status(), 'duration_ms' => $duration];

            if ($path === '/version' && is_array($data)) {
                $details['gateway_version'] = $data['version'] ?? $data['gateway_version'] ?? null;
            }
            if ($path === '/adapters' && is_array($data)) {
                $details['adapters'] = collect($data['adapters'] ?? [])->pluck('name')->filter()->values()->all();
            }

            $this->communicationLogger->record([
                'request_id' => $requestId,
                'method' => 'GET '.$path,
                'duration_ms' => $duration,
                'status' => $ok ? 'ok' : 'failed',
                'http_code' => $response->status(),
                'error_code' => $ok ? null : 'gateway_http_'.$response->status(),
                'metadata' => ['operation' => $path, 'endpoint_class' => 'test'],
            ]);

            return $this->state($ok ? 'ok' : 'failed', $ok ? "Gateway {$path} returned a successful response." : "Gateway {$path} did not return a successful response.", $details);
        } catch (ConnectionException) {
            $duration = (int) round((microtime(true) - $started) * 1000);
            $this->communicationLogger->record([
                'request_id' => $requestId,
                'method' => 'GET '.$path,
                'duration_ms' => $duration,
                'status' => 'failed',
                'error_code' => 'connection_failed',
                'metadata' => ['operation' => $path, 'endpoint_class' => 'test'],
            ]);

            return $this->state('failed', "Gateway {$path} connection failed.", ['error_code' => 'connection_failed', 'duration_ms' => $duration]);
        }
    }

    private function endpoint(string $url): ?array
    {
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $parts['host'] ?? null;
        if (! $host || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'base_url' => $scheme.'://'.$host.($port === ($scheme === 'https' ? 443 : 80) ? '' : ':'.$port),
            'path' => $parts['path'] ?? '/',
        ];
    }

    private function publicEndpointDetails(array $endpoint): array
    {
        return ['scheme' => $endpoint['scheme'], 'host' => $endpoint['host'], 'port' => $endpoint['port']];
    }

    private function hostState(array $tcpState): array
    {
        if (($tcpState['status'] ?? null) === 'ok') {
            return $this->state('ok', 'Gateway host accepted the TCP connection.', $tcpState['details'] ?? []);
        }

        $errorCode = $tcpState['details']['error_code'] ?? 'tcp_unreachable';
        if ($errorCode === 'tcp_refused') {
            return $this->state('observed', 'A TCP refusal was observed. The remote source of the reject cannot be determined from DEV.', $tcpState['details'] ?? []);
        }

        return $this->state('failed', 'Gateway host did not provide conclusive reachability evidence.', $tcpState['details'] ?? []);
    }

    private function tcpErrorCode(int $errorNo, string $error): string
    {
        if (in_array($errorNo, [61, 111, 10061], true) || str_contains(strtolower($error), 'refused')) {
            return 'tcp_refused';
        }
        if (in_array($errorNo, [60, 110, 10060], true) || str_contains(strtolower($error), 'timed out')) {
            return 'tcp_timeout';
        }

        return 'tcp_unreachable';
    }

    private function tcpFailureMessage(string $code): string
    {
        return match ($code) {
            'tcp_refused' => 'TCP connection was refused.',
            'tcp_timeout' => 'TCP connection timed out.',
            default => 'TCP endpoint is unreachable.',
        };
    }

    private function state(string $status, string $message, array $details = []): array
    {
        return ['status' => $status, 'message' => $message, 'details' => $details];
    }
}
