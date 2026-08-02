<?php
require __DIR__ . '/bootstrap.php';

// Mercado Pago envia notificações via query/topic ou body JSON (Orders)
$input = gz_json_input();
$query = $_GET;

gz_log('webhook.log', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'query' => $query,
    'body' => $input,
]);

$secret = gz_env('MP_WEBHOOK_SECRET');
if ($secret !== '') {
    $provided = $_SERVER['HTTP_X_SIGNATURE'] ?? ($_SERVER['HTTP_X_HOOK_SECRET'] ?? '');
    // Validação simples opcional — refine com a doc oficial de assinatura quando configurar
    if ($provided !== '' && !hash_equals($secret, (string) $provided)) {
        gz_respond(401, ['ok' => false, 'message' => 'Assinatura inválida']);
    }
}

$orderId = null;
if (!empty($input['data']['id'])) {
    $orderId = (string) $input['data']['id'];
} elseif (!empty($input['id'])) {
    $orderId = (string) $input['id'];
} elseif (!empty($query['data.id'])) {
    $orderId = (string) $query['data.id'];
} elseif (!empty($query['id'])) {
    $orderId = (string) $query['id'];
}

if ($orderId) {
    $result = gz_mp_request('GET', '/v1/orders/' . rawurlencode($orderId));
    gz_log('webhook-orders.log', [
        'orderId' => $orderId,
        'ok' => $result['ok'],
        'status' => $result['body']['status'] ?? null,
        'status_detail' => $result['body']['status_detail'] ?? null,
        'external_reference' => $result['body']['external_reference'] ?? null,
        'body' => $result['body'],
    ]);
}

gz_respond(200, ['ok' => true]);
