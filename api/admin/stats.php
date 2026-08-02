<?php
require dirname(__DIR__) . '/auth/common.php';

$admin = gz_require_admin();
$users = gz_ddb_scan_all(gz_users_table(), 500);
$orders = gz_ddb_scan_all(gz_orders_table(), 500);
require_once dirname(__DIR__) . '/catalog-lib.php';
$products = gz_products_all(false);

$paid = 0;
foreach ($orders as $o) {
    $st = strtolower((string) ($o['status'] ?? ''));
    if (in_array($st, ['processed', 'accredited', 'approved', 'paid'], true)) {
        $paid++;
    }
}

gz_respond(200, [
    'ok' => true,
    'admin' => gz_public_user($admin),
    'stats' => [
        'users' => count($users),
        'admins' => count(array_filter($users, static fn($u) => ($u['role'] ?? '') === 'admin')),
        'products' => count($products),
        'productsActive' => count(array_filter($products, static fn($p) => !empty($p['active']))),
        'orders' => count($orders),
        'ordersPaid' => $paid,
    ],
]);
