<?php
require __DIR__ . '/auth/common.php';
require_once __DIR__ . '/minecraft-lib.php';
require_once __DIR__ . '/coupons-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$sessionUser = gz_current_user();

$input = gz_json_input();
$vip = strtolower(trim((string) ($input['vip'] ?? '')));
$method = strtolower(trim((string) ($input['method'] ?? 'pix')));
$email = strtolower(trim((string) ($input['email'] ?? '')));
$nick = trim((string) ($input['nick'] ?? ''));
$giftNick = trim((string) ($input['giftNick'] ?? ''));
$couponCode = trim((string) ($input['coupon'] ?? ''));
$cpf = preg_replace('/\D+/', '', (string) ($input['cpf'] ?? '')) ?: '';
$cardToken = trim((string) ($input['cardToken'] ?? ''));
$paymentMethodId = strtolower(trim((string) ($input['paymentMethodId'] ?? '')));
$installments = (int) ($input['installments'] ?? 1);
$issuerId = isset($input['issuerId']) ? (string) $input['issuerId'] : null;

$catalog = gz_catalog();
if (!isset($catalog[$vip])) {
    gz_respond(400, ['ok' => false, 'message' => 'Pacote VIP inválido']);
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    gz_respond(400, ['ok' => false, 'message' => 'E-mail inválido']);
}

if ($nick === '' || strlen($nick) < 3 || strlen($nick) > 16) {
    gz_respond(400, ['ok' => false, 'message' => 'Informe o nick (3 a 16 caracteres)']);
}
if (!preg_match('/^[a-zA-Z0-9_]+$/', $nick)) {
    gz_respond(400, ['ok' => false, 'message' => 'Nick só pode ter letras, números e _']);
}

$mc = gz_mc_lookup_nick($nick);
if (empty($mc['found'])) {
    gz_respond(400, [
        'ok' => false,
        'message' => $mc['message'] ?? 'Nick não encontrado',
    ]);
}
$nick = (string) $mc['nick'];
$buyerNick = $nick;
$deliveryNick = $nick;

if ($giftNick !== '') {
    if (strlen($giftNick) < 3 || strlen($giftNick) > 16 || !preg_match('/^[a-zA-Z0-9_]+$/', $giftNick)) {
        gz_respond(400, ['ok' => false, 'message' => 'Nick do presente inválido']);
    }
    $giftMc = gz_mc_lookup_nick($giftNick);
    if (empty($giftMc['found'])) {
        gz_respond(400, [
            'ok' => false,
            'message' => $giftMc['message'] ?? 'Nick do presente não encontrado',
        ]);
    }
    $deliveryNick = (string) $giftMc['nick'];
}

if (!in_array($method, ['pix', 'credit_card'], true)) {
    gz_respond(400, ['ok' => false, 'message' => 'Forma de pagamento inválida']);
}

$product = $catalog[$vip];
$amountOriginal = (float) $product['amount'];
$amount = number_format($amountOriginal, 2, '.', '');
$couponApplied = null;
if ($couponCode !== '') {
    $coupon = gz_coupon_validate($couponCode);
    if (!$coupon['ok']) {
        gz_respond(400, ['ok' => false, 'message' => $coupon['message'] ?? 'Cupom inválido']);
    }
    $amount = gz_coupon_apply_amount($amountOriginal, (float) $coupon['percent']);
    $couponApplied = [
        'code' => $coupon['code'],
        'percent' => $coupon['percent'],
        'originalAmount' => number_format($amountOriginal, 2, '.', ''),
    ];
}

$externalReference = sprintf(
    'gz_%s_%s_%s',
    $vip,
    preg_replace('/[^a-zA-Z0-9_-]/', '', $deliveryNick),
    bin2hex(random_bytes(4))
);

$paymentMethod = [];
if ($method === 'pix') {
    $paymentMethod = [
        'id' => 'pix',
        'type' => 'bank_transfer',
    ];
} else {
    if ($cardToken === '' || $paymentMethodId === '') {
        gz_respond(400, ['ok' => false, 'message' => 'Dados do cartão incompletos']);
    }
    if ($installments < 1) {
        $installments = 1;
    }
    $paymentMethod = [
        'id' => $paymentMethodId,
        'type' => 'credit_card',
        'token' => $cardToken,
        'installments' => $installments,
        'statement_descriptor' => 'GERACAOZERO',
    ];
    if ($issuerId !== null && $issuerId !== '') {
        $paymentMethod['issuer_id'] = (int) $issuerId;
    }
}

$payer = [
    'email' => $email,
    'first_name' => $buyerNick,
];

if (strlen($cpf) === 11) {
    $payer['identification'] = [
        'type' => 'CPF',
        'number' => $cpf,
    ];
}

$itemDesc = 'Nick: ' . $deliveryNick;
if ($deliveryNick !== $buyerNick) {
    $itemDesc .= ' (presente de ' . $buyerNick . ')';
}
if ($couponApplied) {
    $itemDesc .= ' | Cupom ' . $couponApplied['code'];
}

$orderBody = [
    'type' => 'online',
    'processing_mode' => 'automatic',
    'external_reference' => $externalReference,
    'description' => $product['description'] . ' | ' . $itemDesc,
    'total_amount' => $amount,
    'payer' => $payer,
    'items' => [
        [
            'title' => $product['title'],
            'unit_price' => $amount,
            'quantity' => 1,
            'description' => $itemDesc,
            'category_id' => 'others',
        ],
    ],
    'transactions' => [
        'payments' => [
            [
                'amount' => $amount,
                'payment_method' => $paymentMethod,
            ],
        ],
    ],
];

# Notificações vêm do Webhook cadastrado no painel do MP (Orders API não aceita config.notification_url).

$idempotency = bin2hex(random_bytes(16));
$result = gz_mp_request('POST', '/v1/orders', $orderBody, $idempotency);

gz_log('orders.log', [
    'external_reference' => $externalReference,
    'vip' => $vip,
    'nick' => $buyerNick,
    'deliveryNick' => $deliveryNick,
    'coupon' => $couponApplied['code'] ?? null,
    'method' => $method,
    'amount' => $amount,
    'mp_status' => $result['status'],
    'ok' => $result['ok'],
    'order_id' => $result['body']['id'] ?? null,
]);

if (!$result['ok']) {
    $message = $result['body']['message']
        ?? ($result['body']['error'] ?? 'Não foi possível criar o pagamento');
    if (isset($result['body']['errors']) && is_array($result['body']['errors'])) {
        $message = $result['body']['errors'][0]['message'] ?? $message;
        $errDetails = $result['body']['errors'][0]['details'][0] ?? null;
        if (is_string($errDetails) && $errDetails !== '') {
            // ex.: "PAY01...: high_risk"
            if (preg_match('/:\s*(.+)$/', $errDetails, $m)) {
                $code = trim($m[1]);
                $map = [
                    'high_risk' => 'Pagamento recusado por análise de risco. Tente outro cartão ou use Pix.',
                    'cc_rejected_insufficient_amount' => 'Cartão sem limite suficiente.',
                    'cc_rejected_bad_filled_security_code' => 'CVV inválido.',
                    'cc_rejected_bad_filled_date' => 'Validade do cartão inválida.',
                    'cc_rejected_bad_filled_card_number' => 'Número do cartão inválido.',
                    'cc_rejected_call_for_authorize' => 'Autorize o pagamento com o banco e tente de novo.',
                    'cc_rejected_card_disabled' => 'Cartão desabilitado. Contate o banco.',
                    'cc_rejected_other_reason' => 'Cartão recusado pelo emissor.',
                    'pending_challenge' => 'Confirme a compra no app do banco (3DS).',
                ];
                $message = $map[$code] ?? ('Pagamento recusado: ' . $code);
            }
        }
    }
    // Orders API às vezes devolve o pedido falho em data
    $failedOrder = $result['body']['data'] ?? null;
    if (is_array($failedOrder) && !empty($failedOrder['id'])) {
        $fp = $failedOrder['transactions']['payments'][0] ?? [];
        $failOrder = [
            'id' => (string) $failedOrder['id'],
            'orderId' => (string) $failedOrder['id'],
            'externalReference' => $externalReference,
            'userId' => (string) ($sessionUser['id'] ?? 'guest'),
            'nick' => $buyerNick,
            'deliveryNick' => $deliveryNick,
            'email' => $email,
            'vip' => $vip,
            'productTitle' => (string) ($product['title'] ?? $vip),
            'method' => $method,
            'amount' => $amount,
            'status' => (string) ($failedOrder['status'] ?? 'failed'),
            'statusDetail' => (string) ($fp['status_detail'] ?? ($failedOrder['status_detail'] ?? 'failed')),
            'paymentId' => (string) ($fp['id'] ?? ''),
            'paymentStatus' => (string) ($fp['status'] ?? ''),
            'fulfillmentStatus' => 'pending',
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
        ];
        if ($couponApplied) {
            $failOrder['couponCode'] = $couponApplied['code'];
            $failOrder['couponPercent'] = $couponApplied['percent'];
            $failOrder['amountOriginal'] = $couponApplied['originalAmount'];
        }
        if ($deliveryNick !== $buyerNick) {
            $failOrder['giftNick'] = $deliveryNick;
        }
        gz_save_order($failOrder);
    }
    gz_respond((int) $result['status'] ?: 502, [
        'ok' => false,
        'message' => $message,
        'details' => $result['body'],
    ]);
}

$order = $result['body'];
$payment = $order['transactions']['payments'][0] ?? [];
$pm = $payment['payment_method'] ?? [];

$orderId = (string) ($order['id'] ?? $externalReference);
$saved = [
    'id' => $orderId,
    'orderId' => $orderId,
    'externalReference' => $externalReference,
    'userId' => (string) ($sessionUser['id'] ?? 'guest'),
    'nick' => $buyerNick,
    'deliveryNick' => $deliveryNick,
    'email' => $email,
    'vip' => $vip,
    'productTitle' => (string) ($product['title'] ?? $vip),
    'method' => $method,
    'amount' => $amount,
    'status' => (string) ($order['status'] ?? ''),
    'statusDetail' => (string) ($order['status_detail'] ?? ''),
    'paymentId' => (string) ($payment['id'] ?? ''),
    'fulfillmentStatus' => 'pending',
    'createdAt' => date('c'),
    'updatedAt' => date('c'),
];
if ($couponApplied) {
    $saved['couponCode'] = $couponApplied['code'];
    $saved['couponPercent'] = $couponApplied['percent'];
    $saved['amountOriginal'] = $couponApplied['originalAmount'];
}
if ($deliveryNick !== $buyerNick) {
    $saved['giftNick'] = $deliveryNick;
}
gz_save_order($saved);

$response = [
    'ok' => true,
    'orderId' => $order['id'] ?? null,
    'status' => $order['status'] ?? null,
    'statusDetail' => $order['status_detail'] ?? null,
    'externalReference' => $externalReference,
    'amount' => $amount,
    'vip' => $vip,
    'nick' => $buyerNick,
    'deliveryNick' => $deliveryNick,
    'method' => $method,
    'paymentId' => $payment['id'] ?? null,
];
if ($couponApplied) {
    $response['coupon'] = $couponApplied;
}

if ($method === 'pix') {
    $response['pix'] = [
        'qrCode' => $pm['qr_code'] ?? null,
        'qrCodeBase64' => $pm['qr_code_base64'] ?? null,
        'ticketUrl' => $pm['ticket_url'] ?? null,
    ];
}

gz_respond(201, $response);
