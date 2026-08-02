<?php
/**
 * Cliente DynamoDB mínimo (SigV4) — sem Composer.
 */
declare(strict_types=1);

function gz_aws_region(): string
{
    return gz_env('AWS_REGION', gz_env('AWS_DEFAULT_REGION', 'us-east-1'));
}

function gz_aws_credentials(): array
{
    $key = gz_env('AWS_ACCESS_KEY_ID');
    $secret = gz_env('AWS_SECRET_ACCESS_KEY');
    $token = gz_env('AWS_SESSION_TOKEN');

    if ($key !== '' && $secret !== '') {
        return ['key' => $key, 'secret' => $secret, 'token' => $token];
    }

    // Credenciais temporárias da role da instância (EB/EC2)
    $uri = 'http://169.254.169.254/latest/meta-data/iam/security-credentials/';
    $role = @file_get_contents($uri);
    if ($role === false || trim($role) === '') {
        // IMDSv2
        $tokenCtx = stream_context_create([
            'http' => [
                'method' => 'PUT',
                'header' => "X-aws-ec2-metadata-token-ttl-seconds: 21600\r\n",
                'timeout' => 2,
            ],
        ]);
        $imdsToken = @file_get_contents('http://169.254.169.254/latest/api/token', false, $tokenCtx);
        if ($imdsToken) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "X-aws-ec2-metadata-token: {$imdsToken}\r\n",
                    'timeout' => 2,
                ],
            ]);
            $role = @file_get_contents($uri, false, $ctx);
            if ($role) {
                $credsRaw = @file_get_contents($uri . trim($role), false, $ctx);
                $creds = json_decode((string) $credsRaw, true);
                if (is_array($creds)) {
                    return [
                        'key' => (string) ($creds['AccessKeyId'] ?? ''),
                        'secret' => (string) ($creds['SecretAccessKey'] ?? ''),
                        'token' => (string) ($creds['Token'] ?? ''),
                    ];
                }
            }
        }
        return ['key' => '', 'secret' => '', 'token' => ''];
    }

    $credsRaw = @file_get_contents($uri . trim($role));
    $creds = json_decode((string) $credsRaw, true);
    if (!is_array($creds)) {
        return ['key' => '', 'secret' => '', 'token' => ''];
    }
    return [
        'key' => (string) ($creds['AccessKeyId'] ?? ''),
        'secret' => (string) ($creds['SecretAccessKey'] ?? ''),
        'token' => (string) ($creds['Token'] ?? ''),
    ];
}

function gz_dynamo_request(string $target, array $payload): array
{
    $region = gz_aws_region();
    $service = 'dynamodb';
    $host = "dynamodb.{$region}.amazonaws.com";
    $endpoint = "https://{$host}/";
    $creds = gz_aws_credentials();
    if ($creds['key'] === '' || $creds['secret'] === '') {
        return ['ok' => false, 'status' => 500, 'body' => ['message' => 'Credenciais AWS indisponíveis para DynamoDB']];
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = hash('sha256', $body);

    $headersToSign = [
        'content-type' => 'application/x-amz-json-1.0',
        'host' => $host,
        'x-amz-date' => $amzDate,
        'x-amz-target' => $target,
    ];
    if ($creds['token'] !== '') {
        $headersToSign['x-amz-security-token'] = $creds['token'];
    }

    ksort($headersToSign);
    $canonicalHeaders = '';
    $signedHeadersArr = [];
    foreach ($headersToSign as $k => $v) {
        $canonicalHeaders .= $k . ':' . trim($v) . "\n";
        $signedHeadersArr[] = $k;
    }
    $signedHeaders = implode(';', $signedHeadersArr);
    $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
    $algorithm = 'AWS4-HMAC-SHA256';
    $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
    $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $creds['secret'], true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = "{$algorithm} Credential={$creds['key']}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $httpHeaders = [
        'Content-Type: application/x-amz-json-1.0',
        'X-Amz-Date: ' . $amzDate,
        'X-Amz-Target: ' . $target,
        'Authorization: ' . $authorization,
    ];
    if ($creds['token'] !== '') {
        $httpHeaders[] = 'X-Amz-Security-Token: ' . $creds['token'];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 20,
        ];
        if (strtolower(gz_env('MP_SSL_VERIFY', 'true')) === 'false' || strtolower(gz_env('AWS_SSL_VERIFY', 'true')) === 'false') {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            return ['ok' => false, 'status' => 502, 'body' => ['message' => $err ?: 'Falha DynamoDB']];
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $httpHeaders),
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($endpoint, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if ($response === false) {
            return ['ok' => false, 'status' => 502, 'body' => ['message' => 'Falha DynamoDB stream']];
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

function gz_ddb_to_php($av)
{
    if (!is_array($av)) return null;
    if (isset($av['S'])) return (string) $av['S'];
    if (isset($av['N'])) return $av['N'] + 0;
    if (isset($av['BOOL'])) return (bool) $av['BOOL'];
    if (isset($av['NULL'])) return null;
    if (isset($av['M']) && is_array($av['M'])) {
        $out = [];
        foreach ($av['M'] as $k => $v) $out[$k] = gz_ddb_to_php($v);
        return $out;
    }
    if (isset($av['L']) && is_array($av['L'])) {
        $out = [];
        foreach ($av['L'] as $v) $out[] = gz_ddb_to_php($v);
        return $out;
    }
    return null;
}

function gz_php_to_ddb($value): array
{
    if (is_null($value)) return ['NULL' => true];
    if (is_bool($value)) return ['BOOL' => $value];
    if (is_int($value) || is_float($value)) return ['N' => (string) $value];
    if (is_array($value)) {
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $l = [];
            foreach ($value as $v) $l[] = gz_php_to_ddb($v);
            return ['L' => $l];
        }
        $m = [];
        foreach ($value as $k => $v) $m[(string) $k] = gz_php_to_ddb($v);
        return ['M' => $m];
    }
    return ['S' => (string) $value];
}

function gz_item_to_php(array $item): array
{
    $out = [];
    foreach ($item as $k => $v) $out[$k] = gz_ddb_to_php($v);
    return $out;
}

function gz_php_to_item(array $data): array
{
    $out = [];
    foreach ($data as $k => $v) {
        if ($v === null || $v === '') continue;
        $out[$k] = gz_php_to_ddb($v);
    }
    return $out;
}

function gz_users_table(): string
{
    return gz_env('DDB_USERS_TABLE', 'gz_users');
}

function gz_orders_table(): string
{
    return gz_env('DDB_ORDERS_TABLE', 'gz_orders');
}

function gz_products_table(): string
{
    return gz_env('DDB_PRODUCTS_TABLE', 'gz_products');
}

function gz_ddb_put(string $table, array $item): array
{
    return gz_dynamo_request('DynamoDB_20120810.PutItem', [
        'TableName' => $table,
        'Item' => gz_php_to_item($item),
    ]);
}

function gz_ddb_delete(string $table, array $key): array
{
    return gz_dynamo_request('DynamoDB_20120810.DeleteItem', [
        'TableName' => $table,
        'Key' => gz_php_to_item($key),
    ]);
}

function gz_ddb_get(string $table, array $key): ?array
{
    $res = gz_dynamo_request('DynamoDB_20120810.GetItem', [
        'TableName' => $table,
        'Key' => gz_php_to_item($key),
    ]);
    if (!$res['ok'] || empty($res['body']['Item'])) return null;
    return gz_item_to_php($res['body']['Item']);
}

function gz_ddb_scan_all(string $table, int $maxItems = 500): array
{
    $items = [];
    $startKey = null;
    do {
        $payload = ['TableName' => $table];
        if ($startKey) {
            $payload['ExclusiveStartKey'] = $startKey;
        }
        $res = gz_dynamo_request('DynamoDB_20120810.Scan', $payload);
        if (!$res['ok']) {
            gz_log('ddb.log', ['action' => 'scan_fail', 'table' => $table, 'body' => $res['body']]);
            break;
        }
        foreach (($res['body']['Items'] ?? []) as $it) {
            $items[] = gz_item_to_php($it);
            if (count($items) >= $maxItems) {
                return $items;
            }
        }
        $startKey = $res['body']['LastEvaluatedKey'] ?? null;
    } while ($startKey);
    return $items;
}

function gz_ddb_query_eq(string $table, string $index, string $attr, string $value): array
{
    $res = gz_dynamo_request('DynamoDB_20120810.Query', [
        'TableName' => $table,
        'IndexName' => $index,
        'KeyConditionExpression' => '#k = :v',
        'ExpressionAttributeNames' => ['#k' => $attr],
        'ExpressionAttributeValues' => [':v' => ['S' => $value]],
        'Limit' => 1,
    ]);
    if (!$res['ok'] || empty($res['body']['Items'][0])) return [];
    $items = [];
    foreach ($res['body']['Items'] as $it) $items[] = gz_item_to_php($it);
    return $items;
}
