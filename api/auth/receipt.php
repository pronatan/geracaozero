<?php
/**
 * Recibo / comprovante HTML imprimível (PDF via print do navegador)
 * GET ?orderId=
 */
require __DIR__ . '/common.php';

$user = gz_current_user();
if (!$user) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body><p>Faça login para ver o comprovante. <a href="/login">Entrar</a></p></body></html>';
    exit;
}

$orderId = trim((string) ($_GET['orderId'] ?? ''));
if ($orderId === '') {
    http_response_code(400);
    echo 'orderId obrigatório';
    exit;
}

$order = gz_ddb_get(gz_orders_table(), ['id' => $orderId]);
$uid = (string) ($user['id'] ?? '');
$emailU = strtolower((string) ($user['email'] ?? ''));
$nickU = strtolower((string) ($user['nick'] ?? ''));
$belongs = $order && (
    ($uid !== '' && ($order['userId'] ?? '') === $uid) ||
    ($emailU !== '' && strtolower((string) ($order['email'] ?? '')) === $emailU) ||
    ($nickU !== '' && strtolower((string) ($order['nick'] ?? '')) === $nickU)
);
if (!$belongs) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body><p>Pedido não encontrado.</p></body></html>';
    exit;
}

$paid = in_array(strtolower((string) ($order['status'] ?? '')), ['processed', 'approved'], true)
    || in_array(strtolower((string) ($order['statusDetail'] ?? '')), ['accredited', 'approved'], true)
    || strtolower((string) ($order['fulfillmentStatus'] ?? '')) === 'done';

$title = htmlspecialchars((string) ($order['productTitle'] ?? $order['vip'] ?? 'VIP'), ENT_QUOTES, 'UTF-8');
$nick = htmlspecialchars((string) ($order['nick'] ?? ''), ENT_QUOTES, 'UTF-8');
$delivery = htmlspecialchars((string) ($order['deliveryNick'] ?? $order['giftNick'] ?? $order['nick'] ?? ''), ENT_QUOTES, 'UTF-8');
$amount = htmlspecialchars((string) ($order['amount'] ?? ''), ENT_QUOTES, 'UTF-8');
$coupon = htmlspecialchars((string) ($order['couponCode'] ?? ''), ENT_QUOTES, 'UTF-8');
$ref = htmlspecialchars((string) ($order['externalReference'] ?? $order['id'] ?? ''), ENT_QUOTES, 'UTF-8');
$created = htmlspecialchars((string) ($order['createdAt'] ?? ''), ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars((string) ($order['email'] ?? $user['email'] ?? ''), ENT_QUOTES, 'UTF-8');
$status = htmlspecialchars((string) ($order['status'] ?? ''), ENT_QUOTES, 'UTF-8');
$fulfill = htmlspecialchars((string) ($order['fulfillmentStatus'] ?? 'pending'), ENT_QUOTES, 'UTF-8');

$itemsHtml = '';
if (!empty($order['items']) && is_array($order['items'])) {
    foreach ($order['items'] as $it) {
        $itemsHtml .= '<tr><td>' . htmlspecialchars((string) ($it['title'] ?? $it['vip'] ?? ''), ENT_QUOTES, 'UTF-8') .
            '</td><td>' . (int) ($it['qty'] ?? 1) .
            '</td><td>R$ ' . htmlspecialchars((string) ($it['amount'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
} else {
    $itemsHtml = '<tr><td>' . $title . '</td><td>1</td><td>R$ ' . $amount . '</td></tr>';
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Comprovante <?= $ref ?></title>
  <style>
    body { font-family: Arial, sans-serif; max-width: 720px; margin: 2rem auto; color: #111; }
    h1 { font-size: 1.4rem; }
    table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
    th, td { border: 1px solid #ccc; padding: 0.5rem; text-align: left; }
    .muted { color: #555; font-size: 0.9rem; }
    .badge { display: inline-block; padding: 0.2rem 0.5rem; background: <?= $paid ? '#d4edda' : '#fff3cd' ?>; border-radius: 4px; }
    @media print { .no-print { display: none; } }
  </style>
</head>
<body>
  <p class="no-print"><button onclick="window.print()">Salvar / imprimir PDF</button> · <a href="/conta">Voltar à conta</a></p>
  <h1>Comprovante — Geração Zero</h1>
  <p class="muted">Documento de compra VIP (não é NF-e fiscal da Receita Federal).</p>
  <p><span class="badge"><?= $paid ? 'Pagamento confirmado' : 'Pagamento pendente / em análise' ?></span></p>
  <p><b>Pedido:</b> <?= $ref ?><br>
     <b>Data:</b> <?= $created ?><br>
     <b>E-mail:</b> <?= $email ?><br>
     <b>Comprador (nick):</b> <?= $nick ?><br>
     <b>Entrega VIP:</b> <?= $delivery ?><br>
     <?php if ($coupon): ?><b>Cupom:</b> <?= $coupon ?><br><?php endif; ?>
     <b>Status MP:</b> <?= $status ?> · <b>Liberação:</b> <?= $fulfill ?>
  </p>
  <table>
    <thead><tr><th>Item</th><th>Qtd</th><th>Valor</th></tr></thead>
    <tbody><?= $itemsHtml ?></tbody>
  </table>
  <p><b>Total:</b> R$ <?= $amount ?></p>
  <p class="muted">Geração Zero · geracaozero.ddnsfree.com · Discord: discord.gg/pAtKdPHBk2</p>
  <script>/* opcional: window.print() */</script>
</body>
</html>
