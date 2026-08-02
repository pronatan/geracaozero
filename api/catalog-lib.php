<?php
/**
 * Catálogo de produtos — DynamoDB com fallback hardcoded.
 */
declare(strict_types=1);

require_once __DIR__ . '/dynamodb.php';

function gz_catalog_fallback(): array
{
    return [
        'supreme' => [
            'id' => 'supreme',
            'title' => 'VIP Supreme',
            'amount' => '14.90',
            'priceLabel' => 'R$14,90',
            'description' => 'O primeiro impulso pra quem está recomeçando no Geração Zero.',
            'mpDescription' => 'VIP Supreme - Geração Zero',
            'imageUrl' => 'assets/img/vip-supreme.png?v=1',
            'perks' => [
                'Prefixo Supreme no chat',
                'Kit semanal básico',
                '2 homes extras',
                'Acesso à fila prioritária',
            ],
            'sortOrder' => 1,
            'active' => true,
        ],
        'lacoste' => [
            'id' => 'lacoste',
            'title' => 'VIP Lacoste',
            'amount' => '29.90',
            'priceLabel' => 'R$29,90',
            'description' => 'Mais conforto pra farmar, explorar e dominar o mapa.',
            'mpDescription' => 'VIP Lacoste - Geração Zero',
            'imageUrl' => 'assets/img/vip-lacoste.png?v=1',
            'perks' => [
                'Tudo do Supreme',
                'Prefixo Lacoste',
                'Kit semanal intermediário',
                '5 homes extras',
                '/fly no terreno (limites do servidor)',
            ],
            'sortOrder' => 2,
            'active' => true,
        ],
        'gucci' => [
            'id' => 'gucci',
            'title' => 'VIP Gucci',
            'amount' => '49.90',
            'priceLabel' => 'R$49,90',
            'description' => 'O pacote completo pra quem lidera a geração.',
            'mpDescription' => 'VIP Gucci - Geração Zero',
            'imageUrl' => 'assets/img/vip-gucci.png?v=1',
            'perks' => [
                'Tudo do Lacoste',
                'Prefixo Gucci',
                'Kit semanal premium',
                '10 homes extras',
                'Partículas exclusivas',
                'Suporte prioritário no Discord',
            ],
            'sortOrder' => 3,
            'active' => true,
        ],
    ];
}

function gz_normalize_product(array $p): array
{
    $id = strtolower(trim((string) ($p['id'] ?? '')));
    $amount = (string) ($p['amount'] ?? '0.00');
    $priceLabel = (string) ($p['priceLabel'] ?? '');
    if ($priceLabel === '' && $amount !== '') {
        $priceLabel = 'R$' . str_replace('.', ',', $amount);
    }
    $perks = $p['perks'] ?? [];
    if (!is_array($perks)) {
        $perks = [];
    }
    $active = $p['active'] ?? true;
    if (is_string($active)) {
        $active = strtolower($active) === 'true' || $active === '1';
    }
    return [
        'id' => $id,
        'title' => (string) ($p['title'] ?? $id),
        'amount' => $amount,
        'priceLabel' => $priceLabel,
        'description' => (string) ($p['description'] ?? ''),
        'mpDescription' => (string) ($p['mpDescription'] ?? ($p['title'] ?? '') . ' - Geração Zero'),
        'imageUrl' => (string) ($p['imageUrl'] ?? ''),
        'perks' => array_values($perks),
        'sortOrder' => (int) ($p['sortOrder'] ?? 0),
        'active' => (bool) $active,
        'createdAt' => $p['createdAt'] ?? null,
        'updatedAt' => $p['updatedAt'] ?? null,
    ];
}

function gz_products_all(bool $onlyActive = false): array
{
    static $cache = null;
    static $cacheAt = 0;
    if ($cache !== null && (time() - $cacheAt) < 30) {
        $list = $cache;
    } else {
        $raw = gz_ddb_scan_all(gz_products_table(), 200);
        if (!$raw) {
            $list = array_values(gz_catalog_fallback());
        } else {
            $list = array_map('gz_normalize_product', $raw);
        }
        usort($list, static function ($a, $b) {
            return ($a['sortOrder'] <=> $b['sortOrder']) ?: strcmp($a['id'], $b['id']);
        });
        $cache = $list;
        $cacheAt = time();
    }

    if ($onlyActive) {
        $list = array_values(array_filter($list, static fn($p) => !empty($p['active'])));
    }
    return $list;
}

function gz_product_by_id(string $id): ?array
{
    $id = strtolower(trim($id));
    if ($id === '') {
        return null;
    }
    $item = gz_ddb_get(gz_products_table(), ['id' => $id]);
    if ($item) {
        return gz_normalize_product($item);
    }
    $fallback = gz_catalog_fallback();
    return isset($fallback[$id]) ? gz_normalize_product($fallback[$id]) : null;
}

function gz_save_product(array $product): bool
{
    $product = gz_normalize_product($product);
    if ($product['id'] === '') {
        return false;
    }
    if (empty($product['createdAt'])) {
        $product['createdAt'] = date('c');
    }
    $product['updatedAt'] = date('c');
    // DynamoDB BOOL
    $product['active'] = !empty($product['active']);
    $res = gz_ddb_put(gz_products_table(), $product);
    return $res['ok'];
}

function gz_delete_product(string $id): bool
{
    $res = gz_ddb_delete(gz_products_table(), ['id' => strtolower(trim($id))]);
    return $res['ok'];
}

function gz_product_to_pack(array $p): array
{
    return [
        'titulo' => $p['title'],
        'preco' => $p['priceLabel'],
        'amount' => $p['amount'],
        'img' => $p['imageUrl'],
        'desc' => $p['description'],
        'perks' => $p['perks'],
        'id' => $p['id'],
        'active' => $p['active'],
        'sortOrder' => $p['sortOrder'],
    ];
}
