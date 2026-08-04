<?php
/**
 * Atualiza forgot.php para usar SES
 */
require __DIR__ . '/common.php';
require_once dirname(__DIR__) . '/mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$input = gz_json_input();
$email = strtolower(trim((string) ($input['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    gz_respond(400, ['ok' => false, 'message' => 'E-mail inválido']);
}

$user = gz_find_user_by_email($email);
$generic = [
    'ok' => true,
    'message' => 'Se o e-mail existir, enviaremos instruções.',
    'mailConfigured' => gz_mail_configured(),
];

if (!$user) {
    gz_respond(200, $generic);
}

$token = bin2hex(random_bytes(24));
$user['resetToken'] = hash('sha256', $token);
$user['resetExpires'] = date('c', time() + 3600);
$user['updatedAt'] = date('c');
gz_save_user($user);

$siteUrl = rtrim(gz_env('SITE_URL', 'https://geracaozero.ddnsfree.com'), '/');
$resetUrl = $siteUrl . '/reset-senha?token=' . urlencode($token);

if (gz_mail_configured()) {
    $nick = (string) ($user['nick'] ?? '');
    $text = "Olá {$nick},\n\nPara redefinir sua senha no Geração Zero, abra:\n{$resetUrl}\n\nO link expira em 1 hora.\nSe não foi você, ignore este e-mail.\n";
    $html = '<p>Olá <b>' . htmlspecialchars($nick, ENT_QUOTES, 'UTF-8') . '</b>,</p>' .
        '<p>Para redefinir sua senha, clique:</p>' .
        '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Redefinir senha</a></p>' .
        '<p>O link expira em 1 hora.</p>';
    $sent = gz_mail_send($email, 'Recuperar senha - Geração Zero', $text, $html);
    gz_respond(200, [
        'ok' => true,
        'mailConfigured' => true,
        'message' => $sent['ok']
            ? 'Enviamos um link de recuperação para o seu e-mail.'
            : ('Não foi possível enviar o e-mail: ' . ($sent['message'] ?? 'erro SES') . '. Abra ticket no Discord.'),
    ]);
}

$dev = strtolower(gz_env('GZ_RESET_DEV', 'false')) === 'true';
$payload = $generic;
$payload['message'] = 'E-mail SES ainda não configurado (MAIL_FROM). Abra um ticket no Discord ou ative o SES.';
if ($dev) {
    $payload['devResetUrl'] = $resetUrl;
}
gz_respond(200, $payload);
