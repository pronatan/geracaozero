<?php
require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

gz_logout_user();
gz_respond(200, ['ok' => true, 'message' => 'Sessão encerrada']);
