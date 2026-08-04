<?php
/**
 * Envio de e-mail via Amazon SES (API SigV4).
 * Env: MAIL_FROM=noreply@seudominio.com (identidade verificada no SES)
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dynamodb.php';

function gz_mail_configured(): bool
{
    return trim(gz_env('MAIL_FROM', '')) !== '';
}

function gz_mail_from(): string
{
    return trim(gz_env('MAIL_FROM', ''));
}

/**
 * @return array{ok:bool,message:string,messageId?:string}
 */
function gz_mail_send(string $to, string $subject, string $textBody, string $htmlBody = ''): array
{
    $from = gz_mail_from();
    if ($from === '') {
        return ['ok' => false, 'message' => 'MAIL_FROM não configurado (SES)'];
    }
    $to = strtolower(trim($to));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Destinatário inválido'];
    }

    $creds = gz_aws_credentials();
    if (($creds['key'] ?? '') === '' || ($creds['secret'] ?? '') === '') {
        return ['ok' => false, 'message' => 'Credenciais AWS indisponíveis'];
    }

    $region = gz_env('AWS_REGION', 'us-east-1');
    $host = 'email.' . $region . '.amazonaws.com';
    $url = 'https://' . $host . '/v2/email/outbound-emails';

    $body = ['Text' => ['Data' => $textBody, 'Charset' => 'UTF-8']];
    if ($htmlBody !== '') {
        $body['Html'] = ['Data' => $htmlBody, 'Charset' => 'UTF-8'];
    }

    $payload = json_encode([
        'FromEmailAddress' => $from,
        'Destination' => ['ToAddresses' => [$to]],
        'Content' => [
            'Simple' => [
                'Subject' => ['Data' => $subject, 'Charset' => 'UTF-8'],
                'Body' => $body,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $service = 'ses';
    $canonicalUri = '/v2/email/outbound-emails';
    $payloadHash = hash('sha256', $payload);
    $canonicalHeaders =
        'content-type:application/json' . "\n" .
        'host:' . $host . "\n" .
        'x-amz-date:' . $amzDate . "\n";
    $signedHeaders = 'content-type;host;x-amz-date';
    $canonicalRequest = "POST\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

    $algorithm = 'AWS4-HMAC-SHA256';
    $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
    $stringToSign = $algorithm . "\n" . $amzDate . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $creds['secret'], true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authHeader = $algorithm .
        ' Credential=' . $creds['key'] . '/' . $credentialScope .
        ', SignedHeaders=' . $signedHeaders .
        ', Signature=' . $signature;

    $headers = [
        'Content-Type: application/json',
        'Host: ' . $host,
        'X-Amz-Date: ' . $amzDate,
        'Authorization: ' . $authHeader,
    ];
    if (!empty($creds['token'])) {
        $headers[] = 'X-Amz-Security-Token: ' . $creds['token'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        $decoded = ['message' => $raw ?: $err];
    }

    gz_log('mail.log', [
        'to' => $to,
        'subject' => $subject,
        'status' => $status,
        'ok' => $status >= 200 && $status < 300,
        'body' => $decoded,
    ]);

    if ($status < 200 || $status >= 300) {
        $msg = $decoded['message'] ?? ($decoded['Error']['Message'] ?? 'Falha SES');
        return ['ok' => false, 'message' => is_string($msg) ? $msg : 'Falha SES'];
    }

    return [
        'ok' => true,
        'message' => 'Enviado',
        'messageId' => (string) ($decoded['MessageId'] ?? ''),
    ];
}
