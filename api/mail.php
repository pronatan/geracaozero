<?php
/**
 * Envio de e-mail via Resend (https://resend.com)
 * Env: RESEND_API_KEY=re_xxx
 *      MAIL_FROM="Geração Zero <onboarding@resend.dev>" (ou domínio verificado)
 */
require_once __DIR__ . '/bootstrap.php';

function gz_mail_configured(): bool
{
    return trim(gz_env('RESEND_API_KEY', '')) !== '';
}

function gz_mail_from(): string
{
    $from = trim(gz_env('MAIL_FROM', ''));
    if ($from !== '') {
        return $from;
    }
    return 'Geração Zero <onboarding@resend.dev>';
}

/**
 * @return array{ok:bool,message:string,messageId?:string}
 */
function gz_mail_send(string $to, string $subject, string $textBody, string $htmlBody = ''): array
{
    $apiKey = trim(gz_env('RESEND_API_KEY', ''));
    if ($apiKey === '') {
        return ['ok' => false, 'message' => 'E-mail não configurado (RESEND_API_KEY)'];
    }
    $to = strtolower(trim($to));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Destinatário inválido'];
    }

    $payload = [
        'from' => gz_mail_from(),
        'to' => [$to],
        'subject' => $subject,
        'text' => $textBody,
    ];
    if ($htmlBody !== '') {
        $payload['html'] = $htmlBody;
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
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
        $msg = $decoded['message'] ?? ($decoded['error'] ?? 'Falha ao enviar e-mail');
        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        return ['ok' => false, 'message' => is_string($msg) ? $msg : 'Falha ao enviar e-mail'];
    }

    return [
        'ok' => true,
        'message' => 'Enviado',
        'messageId' => (string) ($decoded['id'] ?? ''),
    ];
}
