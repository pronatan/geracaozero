<?php
/**
 * Login — com suporte a 2FA por e-mail (SES)
 */
require __DIR__ . '/common.php';
require_once dirname(__DIR__) . '/mail.php';
require_once dirname(__DIR__) . '/discord.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$input = gz_json_input();
$login = trim((string) ($input['login'] ?? $input['email'] ?? $input['nick'] ?? ''));
$password = (string) ($input['password'] ?? '');
$code2fa = trim((string) ($input['code'] ?? $input['twoFactorCode'] ?? ''));
$pendingToken = trim((string) ($input['pendingToken'] ?? ''));

// Etapa 2: confirmar código 2FA
if ($pendingToken !== '' && $code2fa !== '') {
    $hash = hash('sha256', $pendingToken);
    $user = null;
    foreach (gz_ddb_scan_all(gz_users_table(), 500) as $row) {
        if (!empty($row['twoFactorPendingHash']) && hash_equals((string) $row['twoFactorPendingHash'], $hash)) {
            $user = $row;
            break;
        }
    }
    if (!$user) {
        gz_respond(401, ['ok' => false, 'message' => 'Sessão 2FA inválida. Faça login de novo.']);
    }
    $exp = strtotime((string) ($user['twoFactorExpires'] ?? ''));
    if (!$exp || $exp < time()) {
        gz_respond(401, ['ok' => false, 'message' => 'Código expirado. Faça login de novo.']);
    }
    if (!hash_equals((string) ($user['twoFactorCodeHash'] ?? ''), hash('sha256', $code2fa))) {
        gz_respond(401, ['ok' => false, 'message' => 'Código 2FA inválido']);
    }

    unset($user['twoFactorPendingHash'], $user['twoFactorCodeHash'], $user['twoFactorExpires']);
    $token = bin2hex(random_bytes(24));
    $user['tokenHash'] = hash('sha256', $token);
    $user['lastLoginAt'] = date('c');
    gz_save_user($user);
    gz_login_user($user);
    gz_respond(200, [
        'ok' => true,
        'message' => 'Login realizado',
        'token' => $token,
        'user' => gz_public_user($user),
    ]);
}

if ($login === '' || $password === '') {
    gz_respond(400, ['ok' => false, 'message' => 'Informe e-mail/nick e senha']);
}

$user = null;
if (strpos($login, '@') !== false || filter_var($login, FILTER_VALIDATE_EMAIL)) {
    $user = gz_find_user_by_email($login);
} else {
    $user = gz_find_user_by_nick($login);
    if (!$user) {
        $user = gz_find_user_by_email($login);
    }
}

if (!$user || empty($user['passwordHash']) || !password_verify($password, $user['passwordHash'])) {
    gz_respond(401, ['ok' => false, 'message' => 'Login ou senha inválidos']);
}

// 2FA ativo → envia código (e-mail SES ou Discord DM)
if (!empty($user['twoFactorEnabled'])) {
    $channel = strtolower((string) ($user['twoFactorChannel'] ?? 'email'));
    if (!in_array($channel, ['email', 'discord'], true)) {
        $channel = 'email';
    }

    $code = (string) random_int(100000, 999999);
    $pending = bin2hex(random_bytes(24));
    $user['twoFactorCodeHash'] = hash('sha256', $code);
    $user['twoFactorPendingHash'] = hash('sha256', $pending);
    $user['twoFactorExpires'] = date('c', time() + 600);
    $user['updatedAt'] = date('c');
    gz_save_user($user);

    $nick = (string) ($user['nick'] ?? '');
    if ($channel === 'discord') {
        $discordId = (string) ($user['discordId'] ?? '');
        if ($discordId === '') {
            gz_respond(400, ['ok' => false, 'message' => '2FA Discord ativo, mas a conta não está vinculada. Contate a staff.']);
        }
        $sent = gz_discord_send_dm(
            $discordId,
            "**Geração Zero — 2FA**\nOlá **{$nick}**, seu código: `{$code}`\nVálido por 10 minutos."
        );
        if (!$sent['ok']) {
            gz_respond(502, ['ok' => false, 'message' => 'Não foi possível enviar o código no Discord: ' . ($sent['message'] ?? '')]);
        }
        gz_respond(200, [
            'ok' => true,
            'requires2fa' => true,
            'pendingToken' => $pending,
            'channel' => 'discord',
            'message' => 'Enviamos um código 2FA na sua DM do Discord.',
        ]);
    }

    if (!gz_mail_configured()) {
        gz_respond(503, [
            'ok' => false,
            'message' => '2FA ativo, mas MAIL_FROM/SES não está configurado. Contate a staff.',
        ]);
    }
    $email = (string) ($user['email'] ?? '');
    $text = "Olá {$nick},\n\nSeu código 2FA Geração Zero: {$code}\nVálido por 10 minutos.\n";
    $html = '<p>Olá <b>' . htmlspecialchars($nick, ENT_QUOTES, 'UTF-8') . '</b>,</p>' .
        '<p>Seu código 2FA: <b style="font-size:1.4rem">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</b></p>' .
        '<p>Válido por 10 minutos.</p>';
    $sent = gz_mail_send($email, 'Código 2FA - Geração Zero', $text, $html);
    if (!$sent['ok']) {
        gz_respond(502, ['ok' => false, 'message' => 'Não foi possível enviar o código 2FA: ' . ($sent['message'] ?? 'SES')]);
    }

    gz_respond(200, [
        'ok' => true,
        'requires2fa' => true,
        'pendingToken' => $pending,
        'channel' => 'email',
        'message' => 'Enviamos um código 2FA para o seu e-mail.',
    ]);
}

$token = bin2hex(random_bytes(24));
$user['tokenHash'] = hash('sha256', $token);
$user['lastLoginAt'] = date('c');
gz_save_user($user);

gz_login_user($user);
gz_log('auth.log', ['action' => 'login', 'nick' => $user['nick'] ?? '', 'email' => $user['email'] ?? '']);

gz_respond(200, [
    'ok' => true,
    'message' => 'Login realizado',
    'token' => $token,
    'user' => gz_public_user($user),
]);
