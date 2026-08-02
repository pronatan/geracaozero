<?php
require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$user = gz_current_user();
if (!$user) {
    gz_respond(200, ['ok' => true, 'authenticated' => false, 'user' => null]);
}

gz_respond(200, [
    'ok' => true,
    'authenticated' => true,
    'user' => gz_public_user($user),
]);
