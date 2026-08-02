<?php
/**
 * Admin: produtos
 * GET / POST / PUT / DELETE
 */
require dirname(__DIR__) . '/auth/common.php';
require dirname(__DIR__) . '/catalog-lib.php';

$admin = gz_require_admin();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $products = gz_products_all(false);
    gz_respond(200, ['ok' => true, 'products' => $products]);
}

if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
    $input = gz_json_input();
    $id = strtolower(trim((string) ($input['id'] ?? '')));
    if ($id === '' || !preg_match('/^[a-z0-9_-]{2,32}$/', $id)) {
        gz_respond(400, ['ok' => false, 'message' => 'id inválido (slug: letters, numbers, _ -)']);
    }
    $title = trim((string) ($input['title'] ?? ''));
    $amount = trim((string) ($input['amount'] ?? ''));
    if ($title === '' || $amount === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
        gz_respond(400, ['ok' => false, 'message' => 'title e amount (ex: 29.90) obrigatórios']);
    }

    $existing = gz_ddb_get(gz_products_table(), ['id' => $id]);
    if ($method === 'POST' && $existing) {
        gz_respond(409, ['ok' => false, 'message' => 'Produto já existe — use PUT para editar']);
    }

    $perks = $input['perks'] ?? [];
    if (is_string($perks)) {
        $perks = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $perks))));
    }
    if (!is_array($perks)) {
        $perks = [];
    }

    $imageUrl = trim((string) ($input['imageUrl'] ?? ''));
    if (strpos($imageUrl, 'data:image/') === 0) {
        if (strlen($imageUrl) > 180000) {
            gz_respond(400, ['ok' => false, 'message' => 'Imagem muito grande (máx ~100KB)']);
        }
        if (!preg_match('#^data:image/(jpeg|jpg|png|webp|gif);base64,#i', $imageUrl)) {
            gz_respond(400, ['ok' => false, 'message' => 'Formato de imagem não suportado']);
        }
    }

    $sortOrder = (int) ($input['sortOrder'] ?? 0);
    if ($method === 'POST' && $sortOrder < 1) {
        $all = gz_products_all(false);
        $max = 0;
        foreach ($all as $p) {
            $n = (int) ($p['sortOrder'] ?? 0);
            if ($n > $max) {
                $max = $n;
            }
        }
        $sortOrder = $max + 1;
    } elseif ($sortOrder < 1) {
        $sortOrder = (int) ($existing['sortOrder'] ?? 99);
    }

    $product = [
        'id' => $id,
        'title' => $title,
        'amount' => $amount,
        'priceLabel' => trim((string) ($input['priceLabel'] ?? '')) ?: ('R$' . str_replace('.', ',', $amount)),
        'description' => trim((string) ($input['description'] ?? '')),
        'mpDescription' => trim((string) ($input['mpDescription'] ?? ($title . ' - Geração Zero'))),
        'imageUrl' => $imageUrl,
        'perks' => $perks,
        'sortOrder' => $sortOrder,
        'active' => array_key_exists('active', $input) ? (bool) $input['active'] : (bool) ($existing['active'] ?? true),
        'createdAt' => $existing['createdAt'] ?? date('c'),
    ];

    if (!gz_save_product($product)) {
        gz_respond(500, ['ok' => false, 'message' => 'Falha ao salvar produto no DynamoDB']);
    }
    gz_log('admin.log', ['action' => $method === 'POST' ? 'create_product' : 'update_product', 'by' => $admin['nick'] ?? '', 'id' => $id]);
    gz_respond($method === 'POST' ? 201 : 200, ['ok' => true, 'product' => gz_normalize_product($product)]);
}

if ($method === 'DELETE') {
    $id = strtolower(trim((string) ($_GET['id'] ?? '')));
    if ($id === '') {
        $input = gz_json_input();
        $id = strtolower(trim((string) ($input['id'] ?? '')));
    }
    if ($id === '') {
        gz_respond(400, ['ok' => false, 'message' => 'id obrigatório']);
    }
    if (!gz_delete_product($id)) {
        gz_respond(500, ['ok' => false, 'message' => 'Falha ao excluir produto']);
    }
    gz_log('admin.log', ['action' => 'delete_product', 'by' => $admin['nick'] ?? '', 'id' => $id]);
    gz_respond(200, ['ok' => true]);
}

gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
