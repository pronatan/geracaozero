<?php
require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$orderId = trim((string) ($_GET['id'] ?? ''));
if ($orderId === '') {
    gz_respond(400, ['ok' => false, 'message' => 'Informe o id da order']);
}

$result = gz_mp_request('GET', '/v1/orders/' . rawurlencode($orderId));
if (!$result['ok']) {
    gz_respond((int) $result['status'] ?: 502, [
        'ok' => false,
        'message' => 'Não foi possível consultar o pagamento',
        'details' => $result['body'],
    ]);
}

$order = $result['body'];
$payment = $order['transactions']['payments'][0] ?? [];

gz_respond(200, [
    'ok' => true,
    'orderId' => $order['id'] ?? $orderId,
    'status' => $order['status'] ?? null,
    'statusDetail' => $order['status_detail'] ?? null,
    'paymentStatus' => $payment['status'] ?? null,
    'paymentStatusDetail' => $payment['status_detail'] ?? null,
    'externalReference' => $order['external_reference'] ?? null,
]);
