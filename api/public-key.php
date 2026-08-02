<?php
require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$publicKey = gz_env('MP_PUBLIC_KEY');
if ($publicKey === '') {
    gz_respond(500, ['ok' => false, 'message' => 'MP_PUBLIC_KEY não configurada']);
}

gz_respond(200, [
    'ok' => true,
    'publicKey' => $publicKey,
    'currency' => 'BRL',
    'locale' => 'pt-BR',
    'methods' => ['pix', 'credit_card'],
]);
