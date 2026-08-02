<?php
/**
 * Helpers de sincronização de pedidos Mercado Pago → DynamoDB
 */
require_once __DIR__ . '/auth/common.php';

function gz_mp_is_paid_status(?string $status, ?string $detail = null, ?string $paymentStatus = null): bool
{
    $vals = [
        strtolower((string) $status),
        strtolower((string) $detail),
        strtolower((string) $paymentStatus),
    ];
    foreach (['processed', 'accredited', 'approved'] as $ok) {
        if (in_array($ok, $vals, true)) {
            return true;
        }
    }
    return false;
}

function gz_mp_apply_order_update(string $orderId, array $orderBody, ?array $existing = null): array
{
    $payment = $orderBody['transactions']['payments'][0] ?? [];
    if ($existing === null) {
        $existing = gz_ddb_get(gz_orders_table(), ['id' => $orderId]) ?: [];
    }
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

    $merged = array_merge($existing, [
        'id' => $orderId,
        'orderId' => $orderId,
        'externalReference' => (string) ($orderBody['external_reference'] ?? ($existing['externalReference'] ?? '')),
        'status' => (string) ($orderBody['status'] ?? ($existing['status'] ?? '')),
        'statusDetail' => (string) ($orderBody['status_detail'] ?? ($existing['statusDetail'] ?? '')),
        'paymentId' => (string) ($payment['id'] ?? ($existing['paymentId'] ?? '')),
        'paymentStatus' => (string) ($payment['status'] ?? ($existing['paymentStatus'] ?? '')),
        'updatedAt' => date('c'),
        'userId' => (string) ($existing['userId'] ?? 'guest'),
        'createdAt' => (string) ($existing['createdAt'] ?? date('c')),
    ]);

    // preservar campos locais importantes
    foreach (['nick', 'email', 'vip', 'productTitle', 'method', 'amount', 'fulfillmentStatus', 'fulfilledAt', 'fulfilledBy', 'adminNote'] as $k) {
        if (!isset($merged[$k]) && isset($existing[$k])) {
            $merged[$k] = $existing[$k];
        }
    }
    if (empty($merged['fulfillmentStatus'])) {
        $merged['fulfillmentStatus'] = 'pending';
    }

    gz_save_order($merged);
    return $merged;
}

function gz_mp_apply_payment_update(array $payment, ?array $existing = null): array
{
    $paymentId = (string) ($payment['id'] ?? '');
    $ext = (string) ($payment['external_reference'] ?? '');
    if ($existing === null) {
        $existing = [];
        if ($ext !== '') {
            $byRef = gz_ddb_query_eq(gz_orders_table(), 'externalReference-index', 'externalReference', $ext);
            $existing = $byRef[0] ?? [];
        }
        if (!$existing && $paymentId !== '') {
            foreach (gz_ddb_scan_all(gz_orders_table(), 200) as $row) {
                if (($row['paymentId'] ?? '') === $paymentId || ($row['id'] ?? '') === $paymentId) {
                    $existing = $row;
                    break;
                }
            }
        }
    }

    $orderId = (string) ($existing['id'] ?? ($paymentId !== '' ? $paymentId : bin2hex(random_bytes(8))));
    $merged = array_merge($existing, [
        'id' => $orderId,
        'orderId' => (string) ($existing['orderId'] ?? $orderId),
        'externalReference' => $ext !== '' ? $ext : (string) ($existing['externalReference'] ?? ''),
        'status' => (string) ($payment['status'] ?? ($existing['status'] ?? '')),
        'statusDetail' => (string) ($payment['status_detail'] ?? ($existing['statusDetail'] ?? '')),
        'paymentId' => $paymentId !== '' ? $paymentId : (string) ($existing['paymentId'] ?? ''),
        'paymentStatus' => (string) ($payment['status'] ?? ($existing['paymentStatus'] ?? '')),
        'updatedAt' => date('c'),
        'userId' => (string) ($existing['userId'] ?? 'guest'),
        'createdAt' => (string) ($existing['createdAt'] ?? date('c')),
    ]);
    if (empty($merged['fulfillmentStatus'])) {
        $merged['fulfillmentStatus'] = 'pending';
    }
    gz_save_order($merged);
    return $merged;
}

/**
 * Busca order no MP e grava status atual no DynamoDB.
 */
function gz_mp_sync_order_by_id(string $orderId): ?array
{
    $local = gz_ddb_get(gz_orders_table(), ['id' => $orderId]) ?: [];
    $result = gz_mp_request('GET', '/v1/orders/' . rawurlencode($orderId));
    if (!$result['ok']) {
        return null;
    }
    return gz_mp_apply_order_update($orderId, $result['body'], $local);
}

function gz_order_needs_mp_refresh(array $order): bool
{
    $fulfill = strtolower((string) ($order['fulfillmentStatus'] ?? 'pending'));
    if ($fulfill === 'done') {
        return false;
    }
    $status = strtolower((string) ($order['status'] ?? ''));
    $detail = strtolower((string) ($order['statusDetail'] ?? ''));
    $pay = strtolower((string) ($order['paymentStatus'] ?? ''));
    if (gz_mp_is_paid_status($status, $detail, $pay)) {
        return false;
    }
    if (in_array($status, ['failed', 'cancelled', 'canceled', 'rejected', 'expired'], true)) {
        return false;
    }
    // pendente / aguardando / desconhecido → consulta MP
    return true;
}
