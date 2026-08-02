<?php
require __DIR__ . '/auth/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

$sessionUser = gz_current_user();

$input = gz_json_input();
$vip = strtolower(trim((string) ($input['vip'] ?? '')));
$method = strtolower(trim((string) ($input['method'] ?? 'pix')));
$email = strtolower(trim((string) ($input['email'] ?? '')));
$nick = trim((string) ($input['nick'] ?? ''));
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

if ($nick === '' || strlen($nick) < 3) {
    gz_respond(400, ['ok' => false, 'message' => 'Informe o nick do Minecraft (mín. 3 caracteres)']);
}

if (!in_array($method, ['pix', 'credit_card'], true)) {
    gz_respond(400, ['ok' => false, 'message' => 'Forma de pagamento inválida']);
}

$product = $catalog[$vip];
$amount = $product['amount'];
$externalReference = sprintf(
    'gz_%s_%s_%s',
    $vip,
    preg_replace('/[^a-zA-Z0-9_-]/', '', $nick),
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
    'first_name' => $nick,
];

if (strlen($cpf) === 11) {
    $payer['identification'] = [
        'type' => 'CPF',
        'number' => $cpf,
    ];
}

$orderBody = [
    'type' => 'online',
    'processing_mode' => 'automatic',
    'external_reference' => $externalReference,
    'description' => $product['description'] . ' | Nick: ' . $nick,
    'total_amount' => $amount,
    'payer' => $payer,
    'items' => [
        [
            'title' => $product['title'],
            'unit_price' => $amount,
            'quantity' => 1,
            'description' => 'Nick: ' . $nick,
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
    'nick' => $nick,
    'method' => $method,
    'mp_status' => $result['status'],
    'ok' => $result['ok'],
    'order_id' => $result['body']['id'] ?? null,
]);

if (!$result['ok']) {
    $message = $result['body']['message']
        ?? ($result['body']['error'] ?? 'Não foi possível criar o pagamento');
    if (isset($result['body']['errors']) && is_array($result['body']['errors'])) {
        $message = $result['body']['errors'][0]['message'] ?? $message;
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
gz_save_order([
    'id' => $orderId,
    'orderId' => $orderId,
    'externalReference' => $externalReference,
    'userId' => (string) ($sessionUser['id'] ?? 'guest'),
    'nick' => $nick,
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
]);

$response = [
    'ok' => true,
    'orderId' => $order['id'] ?? null,
    'status' => $order['status'] ?? null,
    'statusDetail' => $order['status_detail'] ?? null,
    'externalReference' => $externalReference,
    'amount' => $amount,
    'vip' => $vip,
    'nick' => $nick,
    'method' => $method,
    'paymentId' => $payment['id'] ?? null,
];

if ($method === 'pix') {
    $response['pix'] = [
        'qrCode' => $pm['qr_code'] ?? null,
        'qrCodeBase64' => $pm['qr_code_base64'] ?? null,
        'ticketUrl' => $pm['ticket_url'] ?? null,
    ];
}

gz_respond(201, $response);
