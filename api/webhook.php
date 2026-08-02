<?php
require __DIR__ . '/mp-orders.php';

/**
 * Valida assinatura HMAC do webhook Mercado Pago (header x-signature).
 * Manifesto: id:[data.id];request-id:[x-request-id];ts:[ts];
 */
function gz_mp_verify_webhook_signature(string $secret, array $query, array $input): bool
{
    $xSignature = (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? '');
    if ($xSignature === '') {
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

// Order API
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
}

// Pagamentos legacy
if ($type === 'payment' || $type === 'payment.updated' || ctype_digit($dataId) || !$type) {
    if (preg_match('/^PAY/i', $dataId)) {
        $existing = null;
        foreach (gz_ddb_scan_all(gz_orders_table(), 200) as $row) {
            if (($row['paymentId'] ?? '') === $dataId || ($row['id'] ?? '') === $dataId) {
                $existing = $row;
                break;
            }
        }
        $orderKey = (string) ($existing['orderId'] ?? $existing['id'] ?? '');
        if ($orderKey !== '') {
            $result = gz_mp_request('GET', '/v1/orders/' . rawurlencode($orderKey));
            gz_log('webhook-payments.log', [
                'paymentId' => $dataId,
                'resolvedOrderId' => $orderKey,
                'ok' => $result['ok'],
                'status' => $result['body']['status'] ?? null,
            ]);
            if ($result['ok']) {
                gz_mp_apply_order_update($orderKey, $result['body'], $existing);
            }
        } else {
            gz_log('webhook-payments.log', [
                'paymentId' => $dataId,
                'ok' => false,
                'note' => 'PAY id without local order',
            ]);
        }
    } else {
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
}

gz_respond(200, ['ok' => true]);
