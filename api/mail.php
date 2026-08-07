<?php
/**
 * Envio de e-mail via Brevo (https://developers.brevo.com)
 * Env: BREVO_API_KEY=xkeysib-...
 *      MAIL_FROM=Geração Zero <guihcomercial@gmail.com>  (remetente verificado no Brevo)
 */
require_once __DIR__ . '/bootstrap.php';

function gz_mail_configured(): bool
{
    return trim(gz_env('BREVO_API_KEY', '')) !== ''
        || trim(gz_env('RESEND_API_KEY', '')) !== ''; // legado
}

/**
 * @return array{name:string,email:string}
 */
function gz_mail_from_parts(): array
{
    $from = trim(gz_env('MAIL_FROM', ''));
    $defaultEmail = 'guihcomercial@gmail.com';
    $defaultName = 'Geração Zero';

    if ($from === '') {
        return ['name' => $defaultName, 'email' => $defaultEmail];
    }

    // Formato: Nome <email@x.com>
    if (preg_match('/^\s*(.*?)\s*<\s*([^>]+)\s*>\s*$/u', $from, $m)) {
        $name = trim($m[1]);
        $email = strtolower(trim($m[2]));
        if ($name === '') {
            $name = $defaultName;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['name' => $name, 'email' => $email];
        }
    }

    if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return ['name' => $defaultName, 'email' => strtolower($from)];
    }

    return ['name' => $defaultName, 'email' => $defaultEmail];
}

function gz_mail_from(): string
{
    $p = gz_mail_from_parts();
    return $p['name'] . ' <' . $p['email'] . '>';
}

/**
 * @return array{ok:bool,message:string,messageId?:string}
 */
function gz_mail_send(string $to, string $subject, string $textBody, string $htmlBody = ''): array
{
    $apiKey = trim(gz_env('BREVO_API_KEY', ''));
    if ($apiKey === '') {
        return ['ok' => false, 'message' => 'E-mail não configurado (BREVO_API_KEY)'];
    }

    $to = strtolower(trim($to));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Destinatário inválido'];
    }

    $from = gz_mail_from_parts();
    $payload = [
        'sender' => [
            'name' => $from['name'],
            'email' => $from['email'],
        ],
        'to' => [
            ['email' => $to],
        ],
        'subject' => $subject,
        'textContent' => $textBody !== '' ? $textBody : strip_tags($htmlBody),
    ];
    if ($htmlBody !== '') {
        $payload['htmlContent'] = $htmlBody;
    } else {
        $payload['htmlContent'] = '<p>' . nl2br(htmlspecialchars($textBody, ENT_QUOTES, 'UTF-8')) . '</p>';
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'api-key: ' . $apiKey,
            'accept: application/json',
            'content-type: application/json',
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
        'provider' => 'brevo',
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
        'messageId' => (string) ($decoded['messageId'] ?? ''),
    ];
}
