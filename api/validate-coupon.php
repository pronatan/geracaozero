<?php
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/coupons-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$code = '';
$amount = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = gz_json_input();
    $code = (string) ($input['code'] ?? '');
    if (isset($input['amount'])) {
        $amount = (float) $input['amount'];
    }
} else {
    $code = (string) ($_GET['code'] ?? '');
    if (isset($_GET['amount'])) {
        $amount = (float) $_GET['amount'];
    }
}

$result = gz_coupon_validate($code);
if (!$result['ok']) {
    gz_respond(400, $result);
}

$payload = $result;
if ($amount !== null && $amount > 0) {
    $payload['originalAmount'] = number_format($amount, 2, '.', '');
    $payload['amount'] = gz_coupon_apply_amount($amount, (float) $result['percent']);
}

gz_respond(200, $payload);
