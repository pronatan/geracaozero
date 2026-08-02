<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/catalog-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$id = strtolower(trim((string) ($_GET['id'] ?? '')));
if ($id !== '') {
    $p = gz_product_by_id($id);
    if (!$p || empty($p['active'])) {
        gz_respond(404, ['ok' => false, 'message' => 'Produto não encontrado']);
    }
    gz_respond(200, [
        'ok' => true,
        'product' => $p,
        'pack' => gz_product_to_pack($p),
    ]);
}

$products = gz_products_all(true);
$packs = [];
foreach ($products as $p) {
    $packs[$p['id']] = gz_product_to_pack($p);
}

gz_respond(200, [
    'ok' => true,
    'products' => $products,
    'packs' => $packs,
]);
