<?php
require __DIR__ . '/mp-orders.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$orderId = trim((string) ($_GET['id'] ?? ''));
if ($orderId === '') {
    gz_respond(400, ['ok' => false, 'message' => 'Informe o id da order']);
}

$local = gz_ddb_get(gz_orders_table(), ['id' => $orderId]) ?: [];

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
$pm = $payment['payment_method'] ?? [];

// Sempre sincroniza o status no DynamoDB (corrige a lista da conta)
$synced = gz_mp_apply_order_update($orderId, $order, $local);

$methodType = strtolower((string) ($pm['type'] ?? ($synced['method'] ?? ($local['method'] ?? ''))));
if ($methodType === 'bank_transfer' || (($pm['id'] ?? '') === 'pix')) {
    $methodType = 'pix';
}
if ($methodType === 'credit_card') {
    $methodType = 'credit_card';
}

$response = [
    'ok' => true,
    'orderId' => $order['id'] ?? $orderId,
    'status' => $order['status'] ?? ($synced['status'] ?? null),
    'statusDetail' => $order['status_detail'] ?? ($synced['statusDetail'] ?? null),
    'paymentStatus' => $payment['status'] ?? ($synced['paymentStatus'] ?? null),
    'paymentStatusDetail' => $payment['status_detail'] ?? null,
    'externalReference' => $order['external_reference'] ?? ($synced['externalReference'] ?? null),
    'method' => $methodType ?: null,
    'vip' => $synced['vip'] ?? ($local['vip'] ?? null),
    'nick' => $synced['nick'] ?? ($local['nick'] ?? null),
    'amount' => $synced['amount'] ?? ($local['amount'] ?? ($order['total_amount'] ?? null)),
    'productTitle' => $synced['productTitle'] ?? ($local['productTitle'] ?? null),
    'fulfillmentStatus' => $synced['fulfillmentStatus'] ?? 'pending',
    'synced' => true,
];

if (!empty($pm['qr_code']) || !empty($pm['qr_code_base64']) || !empty($pm['ticket_url'])) {
    $response['pix'] = [
        'qrCode' => $pm['qr_code'] ?? null,
        'qrCodeBase64' => $pm['qr_code_base64'] ?? null,
        'ticketUrl' => $pm['ticket_url'] ?? null,
    ];
}

gz_respond(200, $response);
