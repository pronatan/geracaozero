<?php
require __DIR__ . '/common.php';
require_once dirname(__DIR__) . '/minecraft-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$input = gz_json_input();
$nick = trim((string) ($input['nick'] ?? ''));
$email = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['password'] ?? '');
$password2 = (string) ($input['passwordConfirm'] ?? $input['password2'] ?? '');

if (strlen($nick) < 3 || strlen($nick) > 16) {
    gz_respond(400, ['ok' => false, 'message' => 'Nick deve ter entre 3 e 16 caracteres']);
}
if (!preg_match('/^[a-zA-Z0-9_]+$/', $nick)) {
    gz_respond(400, ['ok' => false, 'message' => 'Nick só pode ter letras, números e _']);
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    gz_respond(400, ['ok' => false, 'message' => 'E-mail inválido']);
}
if (strlen($password) < 6) {
    gz_respond(400, ['ok' => false, 'message' => 'Senha deve ter no mínimo 6 caracteres']);
}
if ($password !== $password2) {
    gz_respond(400, ['ok' => false, 'message' => 'As senhas não coincidem']);
}

// Valida nick nas APIs Mojang / TLauncher
$mc = gz_mc_lookup_nick($nick);
if (empty($mc['found'])) {
    gz_respond(400, [
        'ok' => false,
        'message' => $mc['message'] ?? 'Nick não encontrado',
    ]);
}
$nick = (string) $mc['nick']; // capitalização oficial

if (gz_find_user_by_email($email)) {
    gz_respond(409, ['ok' => false, 'message' => 'Este e-mail já está cadastrado']);
}
if (gz_find_user_by_nick($nick)) {
    gz_respond(409, ['ok' => false, 'message' => 'Este nick já está em uso']);
}

$token = bin2hex(random_bytes(24));
$user = [
    'id' => bin2hex(random_bytes(8)),
    'nick' => $nick,
    'email' => $email,
    'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
    'tokenHash' => hash('sha256', $token),
    'role' => 'user',
    'mcUuid' => (string) ($mc['uuid'] ?? ''),
    'mcSource' => (string) ($mc['source'] ?? ''),
    'createdAt' => date('c'),
];

if (!gz_save_user($user)) {
    gz_respond(500, ['ok' => false, 'message' => 'Não foi possível salvar a conta no banco']);
}

gz_login_user($user);
gz_log('auth.log', [
    'action' => 'register',
    'nick' => $nick,
    'email' => $email,
    'mcSource' => $user['mcSource'],
    'mcUuid' => $user['mcUuid'],
]);

gz_respond(201, [
    'ok' => true,
    'message' => 'Conta criada com sucesso',
    'token' => $token,
    'user' => gz_public_user($user),
]);
