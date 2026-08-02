<?php
/**
 * Bootstrap da API Mercado Pago (Geração Zero)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// CORS — front local ou outro domínio chama a API na AWS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function gz_load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function gz_env(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string) $value;
}

function gz_json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function gz_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function gz_catalog(): array
{
    require_once __DIR__ . '/catalog-lib.php';
    $out = [];
    foreach (gz_products_all(true) as $p) {
        $out[$p['id']] = [
            'id' => $p['id'],
            'title' => $p['title'],
            'amount' => $p['amount'],
            'description' => $p['mpDescription'] ?: ($p['title'] . ' - Geração Zero'),
        ];
    }
    return $out;
}

function gz_mp_request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
{
    $token = gz_env('MP_ACCESS_TOKEN');
    if ($token === '') {
        return [
            'ok' => false,
            'status' => 500,
            'body' => ['message' => 'MP_ACCESS_TOKEN não configurado no .env'],
        ];
    }

    $url = 'https://api.mercadopago.com' . $path;
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if ($idempotencyKey) {
        $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
    }

    $payload = $body !== null
        ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ];
        // Em alguns PHP locais (Windows/Scoop) falta CA bundle.
        if (strtolower(gz_env('MP_SSL_VERIFY', 'true')) === 'false') {
            $curlOpts[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOpts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $curlOpts);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return [
                'ok' => false,
                'status' => 502,
                'body' => ['message' => 'Falha ao falar com Mercado Pago', 'error' => $error],
            ];
        }
    } else {
        $headerStr = implode("\r\n", $headers);
        $opts = [
            'http' => [
                'method' => strtoupper($method),
                'header' => $headerStr,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ];
        if ($payload !== null) {
            $opts['http']['content'] = $payload;
        }
        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if ($response === false) {
            return [
                'ok' => false,
                'status' => 502,
                'body' => ['message' => 'Falha ao falar com Mercado Pago (stream)'],
            ];
        }
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        $decoded = ['raw' => $response];
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $decoded,
    ];
}

function gz_log(string $file, array $data): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = date('c') . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($dir . '/' . $file, $line, FILE_APPEND);
}

gz_load_env(dirname(__DIR__) . '/.env');
