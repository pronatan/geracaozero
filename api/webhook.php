<?php
require __DIR__ . '/auth/common.php';

/**
 * Valida assinatura HMAC do webhook Mercado Pago (header x-signature).
 * Manifesto: id:[data.id];request-id:[x-request-id];ts:[ts];
 */
function gz_mp_verify_webhook_signature(string $secret, array $query, array $input): bool
{
    $xSignature = (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? '');
    if ($xSignature === '') {
        // Sem header = não é webhook assinado do MP (ex.: smoke test local)
        return true;
    }

    $ts = '';
    $hash = '';
    foreach (explode(',', $xSignature) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) {
            continue;
        }
        if ($kv[0] === 'ts') {
            $ts = $kv[1];
        }
        if ($kv[0] === 'v1') {
            $hash = $kv[1];
        }
    }
    if ($ts === '' || $hash === '') {
        return false;
    }

    $dataId = (string) ($query['data.id'] ?? $query['data_id'] ?? ($input['data']['id'] ?? ''));
    if ($dataId !== '' && preg_match('/[A-Za-z]/', $dataId)) {
        $dataId = strtolower($dataId);
    }
    $requestId = (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');

    $parts = [];
    if ($dataId !== '') {
        $parts[] = 'id:' . $dataId;
    }
    if ($requestId !== '') {
        $parts[] = 'request-id:' . $requestId;
    }
    $parts[] = 'ts:' . $ts;
    $manifest = implode(';', $parts) . ';';

    $computed = hash_hmac('sha256', $manifest, $secret);
    return hash_equals($computed, $hash);
}

function gz_mp_apply_order_update(string $orderId, array $orderBody): void
{
    $payment = $orderBody['transactions']['payments'][0] ?? [];
    $existing = gz_ddb_get(gz_orders_table(), ['id' => $orderId]) ?: [];
    if (!$existing && !empty($orderBody['external_reference'])) {
        $byRef = gz_ddb_query_eq(
            gz_orders_table(),
            'externalReference-index',
            'externalReference',
            (string) $orderBody['external_reference']
        );
        $existing = $byRef[0] ?? [];
        if ($existing && !empty($existing['id'])) {
            $orderId = (string) $existing['id'];
        }
    }

    gz_save_order(array_merge($existing, [
        'id' => $orderId,
        'orderId' => $orderId,
        'externalReference' => (string) ($orderBody['external_reference'] ?? ($existing['externalReference'] ?? '')),
        'status' => (string) ($orderBody['status'] ?? ''),
        'statusDetail' => (string) ($orderBody['status_detail'] ?? ''),
        'paymentId' => (string) ($payment['id'] ?? ($existing['paymentId'] ?? '')),
        'paymentStatus' => (string) ($payment['status'] ?? ''),
        'updatedAt' => date('c'),
        'userId' => (string) ($existing['userId'] ?? 'guest'),
        'createdAt' => (string) ($existing['createdAt'] ?? date('c')),
    ]));
}

function gz_mp_apply_payment_update(array $payment): void
{
    $paymentId = (string) ($payment['id'] ?? '');
    $ext = (string) ($payment['external_reference'] ?? '');
    $existing = [];

    if ($ext !== '') {
        $byRef = gz_ddb_query_eq(gz_orders_table(), 'externalReference-index', 'externalReference', $ext);
        $existing = $byRef[0] ?? [];
    }
    if (!$existing && $paymentId !== '') {
        // fallback: scan curto por paymentId (poucos pedidos)
        foreach (gz_ddb_scan_all(gz_orders_table(), 200) as $row) {
            if (($row['paymentId'] ?? '') === $paymentId || ($row['id'] ?? '') === $paymentId) {
                $existing = $row;
                break;
            }
        }
    }

    $orderId = (string) ($existing['id'] ?? ($paymentId !== '' ? $paymentId : bin2hex(random_bytes(8))));
    gz_save_order(array_merge($existing, [
        'id' => $orderId,
        'orderId' => (string) ($existing['orderId'] ?? $orderId),
        'externalReference' => $ext !== '' ? $ext : (string) ($existing['externalReference'] ?? ''),
        'status' => (string) ($payment['status'] ?? ($existing['status'] ?? '')),
        'statusDetail' => (string) ($payment['status_detail'] ?? ($existing['statusDetail'] ?? '')),
        'paymentId' => $paymentId,
        'paymentStatus' => (string) ($payment['status'] ?? ''),
        'updatedAt' => date('c'),
        'userId' => (string) ($existing['userId'] ?? 'guest'),
        'createdAt' => (string) ($existing['createdAt'] ?? date('c')),
    ]));
}

$input = gz_json_input();
$query = $_GET;

gz_log('webhook.log', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'query' => $query,
    'body' => $input,
    'x_signature' => isset($_SERVER['HTTP_X_SIGNATURE']) ? 'present' : 'absent',
    'x_request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
]);

$secret = gz_env('MP_WEBHOOK_SECRET');
if ($secret !== '' && !gz_mp_verify_webhook_signature($secret, $query, $input)) {
    gz_log('webhook.log', ['error' => 'invalid_signature']);
    gz_respond(401, ['ok' => false, 'message' => 'Assinatura inválida']);
}

$type = strtolower((string) ($input['type'] ?? $query['type'] ?? $query['topic'] ?? ''));
$dataId = (string) (
    $input['data']['id']
    ?? $query['data.id']
    ?? $query['data_id']
    ?? $input['id']
    ?? $query['id']
    ?? ''
);

if ($dataId === '') {
    gz_respond(200, ['ok' => true, 'ignored' => true]);
}

// Order API (Checkout API Orders)
if ($type === 'order' || str_starts_with(strtoupper($dataId), 'ORD') || $type === '') {
    $result = gz_mp_request('GET', '/v1/orders/' . rawurlencode($dataId));
    gz_log('webhook-orders.log', [
        'orderId' => $dataId,
        'type' => $type,
        'ok' => $result['ok'],
        'status' => $result['body']['status'] ?? null,
        'body' => $result['body'],
    ]);
    if ($result['ok']) {
        gz_mp_apply_order_update($dataId, $result['body']);
        gz_respond(200, ['ok' => true]);
    }
    // Se não for order, tenta payment (legacy)
}

// Pagamentos legacy
if ($type === 'payment' || $type === 'payment.updated' || ctype_digit($dataId) || !$type) {
    $result = gz_mp_request('GET', '/v1/payments/' . rawurlencode($dataId));
    gz_log('webhook-payments.log', [
        'paymentId' => $dataId,
        'type' => $type,
        'ok' => $result['ok'],
        'status' => $result['body']['status'] ?? null,
        'body' => $result['body'],
    ]);
    if ($result['ok']) {
        gz_mp_apply_payment_update($result['body']);
    }
}

gz_respond(200, ['ok' => true]);
