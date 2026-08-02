<?php
require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$input = gz_json_input();
$login = trim((string) ($input['login'] ?? $input['email'] ?? $input['nick'] ?? ''));
$password = (string) ($input['password'] ?? '');

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

gz_login_user($user);
gz_log('auth.log', ['action' => 'login', 'nick' => $user['nick'] ?? '', 'email' => $user['email'] ?? '']);

gz_respond(200, [
    'ok' => true,
    'message' => 'Login realizado',
    'user' => gz_public_user($user),
]);
