<?php
require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$input = gz_json_input();
$token = trim((string) ($input['token'] ?? ''));
$password = (string) ($input['password'] ?? '');

if ($token === '' || strlen($token) < 20) {
    gz_respond(400, ['ok' => false, 'message' => 'Token inválido']);
}
if (strlen($password) < 6) {
    gz_respond(400, ['ok' => false, 'message' => 'Senha deve ter ao menos 6 caracteres']);
}

$tokenHash = hash('sha256', $token);
$found = null;
foreach (gz_ddb_scan_all(gz_users_table(), 500) as $row) {
    if (!empty($row['resetToken']) && hash_equals((string) $row['resetToken'], $tokenHash)) {
        $found = $row;
        break;
    }
}

if (!$found) {
    gz_respond(400, ['ok' => false, 'message' => 'Link inválido ou já usado']);
}

$expires = strtotime((string) ($found['resetExpires'] ?? ''));
if (!$expires || $expires < time()) {
    gz_respond(400, ['ok' => false, 'message' => 'Link expirado. Solicite um novo.']);
}

$found['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);
unset($found['resetToken'], $found['resetExpires']);
$found['updatedAt'] = date('c');
gz_save_user($found);

gz_respond(200, ['ok' => true, 'message' => 'Senha atualizada. Faça login.']);
