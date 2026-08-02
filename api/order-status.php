<?php
require __DIR__ . '/auth/common.php';

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
$methodType = strtolower((string) ($pm['type'] ?? ($local['method'] ?? '')));
if ($methodType === 'bank_transfer' || (($pm['id'] ?? '') === 'pix')) {
    $methodType = 'pix';
}
if ($methodType === 'credit_card') {
    $methodType = 'credit_card';
}

$response = [
    'ok' => true,
    'orderId' => $order['id'] ?? $orderId,
    'status' => $order['status'] ?? null,
    'statusDetail' => $order['status_detail'] ?? null,
    'paymentStatus' => $payment['status'] ?? null,
    'paymentStatusDetail' => $payment['status_detail'] ?? null,
    'externalReference' => $order['external_reference'] ?? ($local['externalReference'] ?? null),
    'method' => $methodType ?: ($local['method'] ?? null),
    'vip' => $local['vip'] ?? null,
    'nick' => $local['nick'] ?? null,
    'amount' => $local['amount'] ?? ($order['total_amount'] ?? null),
    'productTitle' => $local['productTitle'] ?? null,
];

if (!empty($pm['qr_code']) || !empty($pm['qr_code_base64']) || !empty($pm['ticket_url'])) {
    $response['pix'] = [
        'qrCode' => $pm['qr_code'] ?? null,
        'qrCodeBase64' => $pm['qr_code_base64'] ?? null,
        'ticketUrl' => $pm['ticket_url'] ?? null,
    ];
}

gz_respond(200, $response);
