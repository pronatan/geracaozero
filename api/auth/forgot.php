<?php
require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$input = gz_json_input();
$email = strtolower(trim((string) ($input['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    gz_respond(400, ['ok' => false, 'message' => 'E-mail inválido']);
}

$user = gz_find_user_by_email($email);
// Resposta genérica (não revela se o e-mail existe)
$generic = [
    'ok' => true,
    'message' => 'Se o e-mail existir, enviaremos instruções. Sem e-mail configurado no servidor, abra um ticket no Discord.',
    'mailConfigured' => false,
];

if (!$user) {
    gz_respond(200, $generic);
}

$token = bin2hex(random_bytes(24));
$user['resetToken'] = hash('sha256', $token);
$user['resetExpires'] = date('c', time() + 3600);
$user['updatedAt'] = date('c');
gz_save_user($user);

$mailFrom = trim(gz_env('MAIL_FROM', ''));
$siteUrl = rtrim(gz_env('SITE_URL', 'https://geracaozero.ddnsfree.com'), '/');
$resetUrl = $siteUrl . '/reset-senha?token=' . urlencode($token);

if ($mailFrom !== '') {
    $subject = 'Recuperar senha - Geração Zero';
    $body = "Olá {$user['nick']},\n\nPara redefinir sua senha, abra:\n{$resetUrl}\n\nO link expira em 1 hora.\nSe não foi você, ignore este e-mail.\n";
    $headers = 'From: ' . $mailFrom . "\r\n" . 'Content-Type: text/plain; charset=UTF-8';
    $sent = @mail($email, $subject, $body, $headers);
    gz_respond(200, [
        'ok' => true,
        'mailConfigured' => true,
        'message' => $sent
            ? 'Enviamos um link de recuperação para o seu e-mail.'
            : 'Não foi possível enviar o e-mail. Abra um ticket no Discord.',
    ]);
}

$dev = strtolower(gz_env('GZ_RESET_DEV', 'false')) === 'true';
$payload = $generic;
if ($dev) {
    $payload['devResetUrl'] = $resetUrl;
    $payload['message'] = 'Modo dev: use o link de reset (e-mail ainda não configurado).';
}
gz_respond(200, $payload);
