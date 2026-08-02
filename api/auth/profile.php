<?php
/**
 * Perfil do usuário logado
 * GET  — dados + pedidos
 * PUT  — atualizar senha / e-mail
 */
require __DIR__ . '/common.php';

$user = gz_current_user();
if (!$user) {
    gz_respond(401, ['ok' => false, 'message' => 'Faça login']);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $orders = gz_ddb_scan_all(gz_orders_table(), 500);
    $mine = [];
    $uid = (string) ($user['id'] ?? '');
    $email = strtolower((string) ($user['email'] ?? ''));
    $nick = strtolower((string) ($user['nick'] ?? ''));
    foreach ($orders as $o) {
        $match = false;
        if ($uid !== '' && ($o['userId'] ?? '') === $uid) {
            $match = true;
        } elseif ($email !== '' && strtolower((string) ($o['email'] ?? '')) === $email) {
            $match = true;
        } elseif ($nick !== '' && strtolower((string) ($o['nick'] ?? '')) === $nick) {
            $match = true;
        }
        if ($match) {
            $mine[] = [
                'id' => $o['id'] ?? null,
                'vip' => $o['vip'] ?? '',
                'productTitle' => $o['productTitle'] ?? ($o['vip'] ?? ''),
                'amount' => $o['amount'] ?? '',
                'method' => $o['method'] ?? '',
                'status' => $o['status'] ?? '',
                'fulfillmentStatus' => $o['fulfillmentStatus'] ?? 'pending',
                'createdAt' => $o['createdAt'] ?? null,
            ];
        }
    }
    usort($mine, static function ($a, $b) {
        return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
    });

    gz_respond(200, [
        'ok' => true,
        'user' => gz_public_user($user),
        'orders' => $mine,
    ]);
}

if ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
    $input = gz_json_input();

    if (!empty($input['email'])) {
        $email = strtolower(trim((string) $input['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            gz_respond(400, ['ok' => false, 'message' => 'E-mail inválido']);
        }
        $other = gz_find_user_by_email($email);
        if ($other && ($other['id'] ?? '') !== ($user['id'] ?? '')) {
            gz_respond(409, ['ok' => false, 'message' => 'E-mail já em uso']);
        }
        $user['email'] = $email;
    }

    if (!empty($input['password'])) {
        $current = (string) ($input['currentPassword'] ?? '');
        if ($current === '' || empty($user['passwordHash']) || !password_verify($current, $user['passwordHash'])) {
            gz_respond(400, ['ok' => false, 'message' => 'Senha atual incorreta']);
        }
        if (strlen((string) $input['password']) < 6) {
            gz_respond(400, ['ok' => false, 'message' => 'Nova senha: mínimo 6 caracteres']);
        }
        $confirm = (string) ($input['passwordConfirm'] ?? $input['password']);
        if ((string) $input['password'] !== $confirm) {
            gz_respond(400, ['ok' => false, 'message' => 'Confirmação de senha não confere']);
        }
        $user['passwordHash'] = password_hash((string) $input['password'], PASSWORD_DEFAULT);
        // mantém o token atual válido
    }

    if (array_key_exists('avatar', $input)) {
        $avatar = $input['avatar'];
        if ($avatar === null || $avatar === '') {
            $user['avatar'] = '';
        } else {
            $avatar = (string) $avatar;
            if (strpos($avatar, 'data:image/') !== 0) {
                gz_respond(400, ['ok' => false, 'message' => 'Avatar inválido']);
            }
            // ~120KB base64 ≈ imagem pequena redimensionada
            if (strlen($avatar) > 160000) {
                gz_respond(400, ['ok' => false, 'message' => 'Imagem muito grande (máx ~100KB)']);
            }
            if (!preg_match('#^data:image/(jpeg|jpg|png|webp|gif);base64,#i', $avatar)) {
                gz_respond(400, ['ok' => false, 'message' => 'Formato de imagem não suportado']);
            }
            $user['avatar'] = $avatar;
        }
    }

    if (!gz_save_user($user)) {
        gz_respond(500, ['ok' => false, 'message' => 'Não foi possível salvar']);
    }

    gz_respond(200, [
        'ok' => true,
        'message' => 'Conta atualizada',
        'user' => gz_public_user($user),
    ]);
}

gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
