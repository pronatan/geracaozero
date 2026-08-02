<?php
require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$user = gz_current_user();
if ($user) {
    $user['tokenHash'] = 'revoked_' . bin2hex(random_bytes(8));
    gz_save_user($user);
}

gz_logout_user();
gz_respond(200, ['ok' => true, 'message' => 'Sessão encerrada']);
