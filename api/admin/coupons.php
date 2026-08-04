<?php
/**
 * Admin: cupons
 * GET / POST / PUT / DELETE
 */
require dirname(__DIR__) . '/auth/common.php';
require_once dirname(__DIR__) . '/coupons-lib.php';

$admin = gz_require_admin();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    gz_respond(200, ['ok' => true, 'coupons' => gz_coupons_all(false)]);
}

if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
    $input = gz_json_input();
    $code = gz_coupon_normalize((string) ($input['code'] ?? ''));
    $percent = (float) ($input['percent'] ?? 0);
    if ($code === '' || strlen($code) < 3) {
        gz_respond(400, ['ok' => false, 'message' => 'Código inválido (mín. 3 caracteres)']);
    }
    if ($percent < 1 || $percent > 90) {
        gz_respond(400, ['ok' => false, 'message' => 'Percentual entre 1 e 90']);
    }

    $existing = gz_coupon_by_code($code);
    if ($method === 'POST' && $existing) {
        gz_respond(409, ['ok' => false, 'message' => 'Cupom já existe — use editar']);
    }

    $coupon = [
        'code' => $code,
        'percent' => $percent,
        'active' => array_key_exists('active', $input) ? (bool) $input['active'] : (bool) ($existing['active'] ?? true),
        'maxUses' => (int) ($input['maxUses'] ?? ($existing['maxUses'] ?? 0)),
        'usedCount' => (int) ($existing['usedCount'] ?? 0),
        'expiresAt' => trim((string) ($input['expiresAt'] ?? ($existing['expiresAt'] ?? ''))),
        'note' => trim((string) ($input['note'] ?? ($existing['note'] ?? ''))),
        'createdAt' => $existing['createdAt'] ?? date('c'),
    ];

    if (!gz_save_coupon($coupon)) {
        gz_respond(500, ['ok' => false, 'message' => 'Falha ao salvar cupom']);
    }
    gz_log('admin.log', ['action' => $method === 'POST' ? 'create_coupon' : 'update_coupon', 'by' => $admin['nick'] ?? '', 'code' => $code]);
    gz_respond($method === 'POST' ? 201 : 200, ['ok' => true, 'coupon' => gz_normalize_coupon($coupon)]);
}

if ($method === 'DELETE') {
    $code = gz_coupon_normalize((string) ($_GET['code'] ?? ''));
    if ($code === '') {
        $input = gz_json_input();
        $code = gz_coupon_normalize((string) ($input['code'] ?? ''));
    }
    if ($code === '') {
        gz_respond(400, ['ok' => false, 'message' => 'code obrigatório']);
    }
    if (!gz_delete_coupon($code)) {
        gz_respond(500, ['ok' => false, 'message' => 'Falha ao excluir cupom']);
    }
    gz_log('admin.log', ['action' => 'delete_coupon', 'by' => $admin['nick'] ?? '', 'code' => $code]);
    gz_respond(200, ['ok' => true]);
}

gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
