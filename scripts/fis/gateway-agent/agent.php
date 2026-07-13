<?php

$envFile = __DIR__.'/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

function envv(string $key, ?string $default = null): ?string { return $_ENV[$key] ?? getenv($key) ?: $default; }
function json_response(array $payload, int $code = 200): never { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); exit; }
function forbidden(string $message): never { json_response(['ok' => false, 'message' => $message], 403); }
function redacted_headers(): array { return ['content-type' => $_SERVER['CONTENT_TYPE'] ?? null, 'request-id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null]; }

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$allowed = array_filter(array_map('trim', explode(',', envv('FIS_AGENT_ALLOWED_IPS', '127.0.0.1,::1'))));
if ($allowed && ! in_array($clientIp, $allowed, true)) forbidden('Client IP is not allowlisted.');

if ($path !== '/health') {
    $expected = envv('FIS_AGENT_TOKEN', 'change-me');
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (! hash_equals('Bearer '.$expected, $auth)) forbidden('Invalid gateway token.');
}

if ($path === '/health') {
    json_response(['ok' => true, 'service' => 'fis-gateway-agent', 'send_enabled' => envv('FIS_AGENT_ENABLE_SEND', 'false') === 'true']);
}

if ($path === '/zkspd/check') {
    $endpoint = envv('FIS_TEST_ENDPOINT', 'http://10.0.3.1:8383/api/import/importservice.svc');
    $parts = parse_url($endpoint);
    $host = $parts['host'] ?? '10.0.3.1';
    $port = (int) ($parts['port'] ?? 80);
    $start = microtime(true);
    $errno = 0; $errstr = '';
    $socket = @fsockopen($host, $port, $errno, $errstr, (float) envv('FIS_CONNECT_TIMEOUT', '5'));
    $latency = (int) round((microtime(true) - $start) * 1000);
    if (is_resource($socket)) fclose($socket);
    json_response(['ok' => (bool) $socket, 'endpoint' => $endpoint, 'host' => $host, 'port' => $port, 'latency_ms' => $latency, 'error' => $socket ? null : trim($errstr ?: ('errno '.$errno))]);
}

if ($path === '/fis/test/send') {
    if (envv('FIS_AGENT_ENABLE_SEND', 'false') !== 'true') {
        json_response(['ok' => false, 'message' => 'FIS TEST send is disabled until official WSDL/XSD/spec 4.9 is configured on gateway.', 'headers' => redacted_headers()], 409);
    }
    json_response(['ok' => false, 'message' => 'Official SOAP contract implementation is not installed in gateway agent yet.'], 501);
}

if ($path === '/fis/test/status') {
    json_response(['ok' => false, 'message' => 'Official status method is not configured yet.'], 501);
}

json_response(['ok' => false, 'message' => 'Not found.'], 404);
