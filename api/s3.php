<?php
/**
 * Upload de imagens para S3 (produtos / avatares)
 * Requer dynamodb.php (credenciais AWS) já carregado.
 */
declare(strict_types=1);

function gz_s3_assets_bucket(): string
{
    return gz_env('S3_ASSETS_BUCKET', 'geracaozero-assets-983902695861');
}

function gz_s3_assets_base_url(): string
{
    $base = rtrim(gz_env('S3_ASSETS_BASE_URL', ''), '/');
    if ($base !== '') {
        return $base;
    }
    $bucket = gz_s3_assets_bucket();
    $region = gz_aws_region();
    return "https://{$bucket}.s3.{$region}.amazonaws.com";
}

/**
 * PUT Object no S3 (público via bucket policy).
 * @return array{ok:bool,url?:string,key?:string,status?:int,message?:string,body?:mixed}
 */
function gz_s3_put_object(string $key, string $bytes, string $contentType, int $cacheSeconds = 31536000): array
{
    $key = ltrim($key, '/');
    if ($key === '' || strpos($key, '..') !== false) {
        return ['ok' => false, 'message' => 'Chave S3 inválida'];
    }

    $region = gz_aws_region();
    $service = 's3';
    $bucket = gz_s3_assets_bucket();
    $host = "{$bucket}.s3.{$region}.amazonaws.com";
    $endpoint = "https://{$host}/" . str_replace('%2F', '/', rawurlencode($key));
    // Encode each path segment
    $parts = explode('/', $key);
    $encParts = array_map('rawurlencode', $parts);
    $canonicalUri = '/' . implode('/', $encParts);
    $endpoint = "https://{$host}" . $canonicalUri;

    $creds = gz_aws_credentials();
    if ($creds['key'] === '' || $creds['secret'] === '') {
        return ['ok' => false, 'message' => 'Credenciais AWS indisponíveis para S3'];
    }

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = hash('sha256', $bytes);

    $headersToSign = [
        'content-type' => $contentType,
        'host' => $host,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $amzDate,
        'cache-control' => 'public, max-age=' . $cacheSeconds . ', immutable',
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
    $canonicalRequest = "PUT\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
    $algorithm = 'AWS4-HMAC-SHA256';
    $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
    $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $creds['secret'], true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    $authorization = "{$algorithm} Credential={$creds['key']}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $httpHeaders = [];
    foreach ($headersToSign as $k => $v) {
        $httpHeaders[] = $k . ': ' . $v;
    }
    $httpHeaders[] = 'Authorization: ' . $authorization;

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $bytes,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'status' => 0, 'message' => 'Falha cURL S3: ' . $err];
    }
    if ($status < 200 || $status >= 300) {
        $parts = explode("\r\n\r\n", (string) $raw, 2);
        return [
            'ok' => false,
            'status' => $status,
            'message' => 'S3 recusou upload',
            'body' => $parts[1] ?? $raw,
        ];
    }

    $url = gz_s3_assets_base_url() . '/' . implode('/', $encParts);
    return ['ok' => true, 'url' => $url, 'key' => $key, 'status' => $status];
}

/**
 * Converte data URL base64 em bytes + content-type.
 * @return array{ok:bool,bytes?:string,contentType?:string,ext?:string,message?:string}
 */
function gz_parse_data_image(string $dataUrl, int $maxChars = 500000): array
{
    $dataUrl = trim($dataUrl);
    if (strlen($dataUrl) > $maxChars) {
        return ['ok' => false, 'message' => 'Imagem muito grande'];
    }
    if (!preg_match('#^data:image/(jpeg|jpg|png|webp|gif);base64,(.+)$#is', $dataUrl, $m)) {
        return ['ok' => false, 'message' => 'Formato de imagem não suportado'];
    }
    $extMap = [
        'jpeg' => 'jpg',
        'jpg' => 'jpg',
        'png' => 'png',
        'webp' => 'webp',
        'gif' => 'gif',
    ];
    $type = strtolower($m[1]);
    $ext = $extMap[$type] ?? 'jpg';
    $contentType = 'image/' . ($type === 'jpg' ? 'jpeg' : $type);
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || $bytes === '') {
        return ['ok' => false, 'message' => 'Base64 inválido'];
    }
    if (strlen($bytes) > 2_500_000) {
        return ['ok' => false, 'message' => 'Arquivo decodificado muito grande'];
    }
    return [
        'ok' => true,
        'bytes' => $bytes,
        'contentType' => $contentType,
        'ext' => $ext,
    ];
}

/**
 * Se $value for data URL, sobe pro S3 e devolve URL pública.
 * Se já for http(s), devolve como está.
 * Se vazio, devolve ''.
 */
function gz_store_image_value(string $value, string $folder, string $nameHint = ''): array
{
    $value = trim($value);
    if ($value === '') {
        return ['ok' => true, 'url' => ''];
    }
    if (preg_match('#^https?://#i', $value)) {
        // Não aceitar data: disfarçado; só URLs http(s)
        return ['ok' => true, 'url' => $value];
    }
    if (strpos($value, 'data:image/') !== 0) {
        return ['ok' => false, 'message' => 'Imagem inválida (use upload ou URL http)'];
    }

    $parsed = gz_parse_data_image($value);
    if (empty($parsed['ok'])) {
        return ['ok' => false, 'message' => $parsed['message'] ?? 'Falha ao ler imagem'];
    }

    $folder = trim($folder, '/');
    $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '', $nameHint) ?: 'img';
    $safe = substr($safe, 0, 40);
    $key = $folder . '/' . $safe . '-' . bin2hex(random_bytes(8)) . '.' . $parsed['ext'];

    $put = gz_s3_put_object($key, $parsed['bytes'], $parsed['contentType']);
    if (empty($put['ok'])) {
        return [
            'ok' => false,
            'message' => $put['message'] ?? 'Falha ao enviar para S3',
            'detail' => $put['body'] ?? null,
        ];
    }
    return ['ok' => true, 'url' => $put['url'], 'key' => $put['key']];
}
