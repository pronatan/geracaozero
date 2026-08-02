<?php
/**
 * Lookup de nick Minecraft: Mojang (oficial) + TLauncher
 * GET ?nick=Steve
 */
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/minecraft-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$nick = trim((string) ($_GET['nick'] ?? ''));
if ($nick === '' || strlen($nick) < 3 || strlen($nick) > 16) {
    gz_respond(400, ['ok' => false, 'message' => 'Nick deve ter entre 3 e 16 caracteres']);
}
if (!preg_match('/^[a-zA-Z0-9_]+$/', $nick)) {
    gz_respond(400, ['ok' => false, 'message' => 'Nick só pode ter letras, números e _']);
}

$result = gz_mc_lookup_nick($nick);
gz_respond(200, array_merge(['ok' => true], $result));
