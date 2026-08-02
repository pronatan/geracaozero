<?php
/**
 * Admin: pedidos
 * GET — listar
 * PUT — atualizar status / fulfillment
 */
require dirname(__DIR__) . '/auth/common.php';

$admin = gz_require_admin();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $orders = gz_ddb_scan_all(gz_orders_table(), 500);
    usort($orders, static function ($a, $b) {
        return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
    });
    gz_respond(200, ['ok' => true, 'orders' => $orders]);
}

if ($method === 'PUT' || $method === 'PATCH') {
    $input = gz_json_input();
    $id = trim((string) ($input['id'] ?? ''));
    if ($id === '') {
        gz_respond(400, ['ok' => false, 'message' => 'id obrigatório']);
    }
    $order = gz_ddb_get(gz_orders_table(), ['id' => $id]);
    if (!$order) {
        gz_respond(404, ['ok' => false, 'message' => 'Pedido não encontrado']);
    }
    if (isset($input['status'])) {
        $order['status'] = (string) $input['status'];
    }
    if (isset($input['statusDetail'])) {
        $order['statusDetail'] = (string) $input['statusDetail'];
    }
    if (isset($input['fulfillmentStatus'])) {
        $order['fulfillmentStatus'] = (string) $input['fulfillmentStatus'];
        if ($order['fulfillmentStatus'] === 'done') {
            $order['fulfilledAt'] = date('c');
            $order['fulfilledBy'] = $admin['nick'] ?? $admin['id'] ?? '';
        }
    }
    if (isset($input['adminNote'])) {
        $order['adminNote'] = (string) $input['adminNote'];
    }
    $order['updatedAt'] = date('c');
    if (!gz_save_order($order)) {
        gz_respond(500, ['ok' => false, 'message' => 'Falha ao atualizar pedido']);
    }
    gz_log('admin.log', ['action' => 'update_order', 'by' => $admin['nick'] ?? '', 'orderId' => $id]);
    gz_respond(200, ['ok' => true, 'order' => $order]);
}

gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
