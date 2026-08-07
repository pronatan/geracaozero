<?php
/**
 * Perfil do usuário logado
 * GET  — dados + pedidos
 * PUT  — atualizar senha / e-mail / avatar
 * DELETE — excluir pedido próprio (?orderId=)
 */
require __DIR__ . '/common.php';
require_once dirname(__DIR__) . '/mp-orders.php';
require_once dirname(__DIR__) . '/s3.php';

$user = gz_current_user();
if (!$user) {
    gz_respond(401, ['ok' => false, 'message' => 'Faça login']);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

function gz_order_belongs_to_user(array $order, array $user): bool
{
    $uid = (string) ($user['id'] ?? '');
    $email = strtolower((string) ($user['email'] ?? ''));
    $nick = strtolower((string) ($user['nick'] ?? ''));
    if ($uid !== '' && ($order['userId'] ?? '') === $uid) {
        return true;
    }
    if ($email !== '' && strtolower((string) ($order['email'] ?? '')) === $email) {
        return true;
    }
    if ($nick !== '' && strtolower((string) ($order['nick'] ?? '')) === $nick) {
        return true;
    }
    return false;
}

if ($method === 'GET') {
    $orders = gz_ddb_scan_all(gz_orders_table(), 500);
    $mine = [];
    $refreshed = 0;
    foreach ($orders as $o) {
        if (!gz_order_belongs_to_user($o, $user)) {
            continue;
        }
        // Atualiza pedidos ainda abertos consultando o Mercado Pago (máx 8)
        $oid = (string) ($o['id'] ?? '');
        if ($oid !== '' && $refreshed < 8 && gz_order_needs_mp_refresh($o)) {
            $synced = gz_mp_sync_order_by_id($oid);
            if (is_array($synced)) {
                $o = $synced;
                $refreshed++;
            }
        }
        $mine[] = [
            'id' => $o['id'] ?? null,
            'vip' => $o['vip'] ?? '',
            'productTitle' => $o['productTitle'] ?? ($o['vip'] ?? ''),
            'amount' => $o['amount'] ?? '',
            'method' => $o['method'] ?? '',
            'status' => $o['status'] ?? '',
            'statusDetail' => $o['statusDetail'] ?? '',
            'paymentStatus' => $o['paymentStatus'] ?? '',
            'fulfillmentStatus' => $o['fulfillmentStatus'] ?? 'pending',
            'createdAt' => $o['createdAt'] ?? null,
        ];
    }
    usort($mine, static function ($a, $b) {
        return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
    });

    gz_respond(200, [
        'ok' => true,
        'user' => gz_public_user($user),
        'orders' => $mine,
        'refreshed' => $refreshed,
    ]);
}

if ($method === 'DELETE') {
    $input = gz_json_input();
    $orderId = trim((string) ($_GET['orderId'] ?? ($input['orderId'] ?? $input['id'] ?? '')));
    if ($orderId === '') {
        gz_respond(400, ['ok' => false, 'message' => 'Informe o pedido']);
    }
    $order = gz_ddb_get(gz_orders_table(), ['id' => $orderId]);
    if (!$order) {
        gz_respond(404, ['ok' => false, 'message' => 'Pedido não encontrado']);
    }
    if (!gz_order_belongs_to_user($order, $user)) {
        gz_respond(403, ['ok' => false, 'message' => 'Você só pode excluir seus próprios pedidos']);
    }
    $res = gz_ddb_delete(gz_orders_table(), ['id' => $orderId]);
    if (!$res['ok']) {
        gz_respond(500, ['ok' => false, 'message' => 'Falha ao excluir pedido']);
    }
    gz_log('orders.log', [
        'action' => 'user_delete_order',
        'userId' => $user['id'] ?? '',
        'nick' => $user['nick'] ?? '',
        'orderId' => $orderId,
    ]);
    gz_respond(200, ['ok' => true, 'deleted' => $orderId]);
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
    }

    if (array_key_exists('avatar', $input)) {
        $avatar = $input['avatar'];
        if ($avatar === null || $avatar === '') {
            $user['avatar'] = '';
        } else {
            $avatar = (string) $avatar;
            $nickHint = (string) ($user['nick'] ?? $user['id'] ?? 'user');
            $stored = gz_store_image_value($avatar, 'avatars', $nickHint);
            if (empty($stored['ok'])) {
                gz_respond(400, [
                    'ok' => false,
                    'message' => $stored['message'] ?? 'Falha ao salvar avatar no S3',
                ]);
            }
            $user['avatar'] = (string) ($stored['url'] ?? '');
        }
    }

    // 2FA (e-mail ou Discord DM)
    if (array_key_exists('twoFactorEnabled', $input)) {
        $enable = !empty($input['twoFactorEnabled']);
        if ($enable) {
            $channel = 'email';
            if ($channel === 'email') {
                require_once dirname(__DIR__) . '/mail.php';
                if (!gz_mail_configured()) {
                    gz_respond(503, ['ok' => false, 'message' => 'Envio de e-mail ainda não está configurado. Contate a staff.']);
                }
                if (empty($user['email'])) {
                    gz_respond(400, ['ok' => false, 'message' => 'Defina um e-mail antes de ativar 2FA']);
                }
            }
            $user['twoFactorEnabled'] = true;
            $user['twoFactorChannel'] = 'email';
        } else {
            $user['twoFactorEnabled'] = false;
            unset($user['twoFactorCodeHash'], $user['twoFactorPendingHash'], $user['twoFactorExpires']);
        }
    } elseif (!empty($input['twoFactorChannel'])) {
        $user['twoFactorChannel'] = 'email';
    }

    // Desvincular Discord (legado)
    if (!empty($input['unlinkDiscord'])) {
        unset($user['discordId'], $user['discordUsername']);
        $user['twoFactorChannel'] = 'email';
    }

    // Contas vinculadas (Java / Bedrock / TLauncher)
    if (array_key_exists('linkedAccounts', $input) && is_array($input['linkedAccounts'])) {
        require_once dirname(__DIR__) . '/minecraft-lib.php';
        $linked = [];
        foreach ($input['linkedAccounts'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string) ($row['type'] ?? 'java')));
            if (!in_array($type, ['java', 'bedrock', 'tlauncher'], true)) {
                $type = 'java';
            }
            $nick = trim((string) ($row['nick'] ?? ''));
            if ($nick === '' || strlen($nick) > 32) {
                continue;
            }
            $entry = [
                'type' => $type,
                'nick' => $nick,
                'uuid' => null,
                'source' => $type,
            ];
            if ($type === 'bedrock') {
                // Gamertag Bedrock — sem lookup Mojang
                $entry['nick'] = preg_replace('/\s+/', ' ', $nick) ?? $nick;
            } else {
                $look = gz_mc_lookup_nick($nick);
                if (empty($look['found'])) {
                    gz_respond(400, [
                        'ok' => false,
                        'message' => 'Nick inválido (' . $type . '): ' . $nick .
                            ($look['message'] ?? ''),
                    ]);
                }
                $entry['nick'] = (string) ($look['nick'] ?? $nick);
                $entry['uuid'] = $look['uuid'] ?? null;
                $entry['source'] = $look['source'] ?? $type;
                if ($type === 'tlauncher' && ($look['source'] ?? '') === 'mojang') {
                    // ok — pode ser premium também
                }
            }
            $linked[] = $entry;
            if (count($linked) >= 8) {
                break;
            }
        }
        $user['linkedAccounts'] = $linked;
    }

    // Atalho: adicionar uma conta
    if (!empty($input['addLinkedAccount']) && is_array($input['addLinkedAccount'])) {
        require_once dirname(__DIR__) . '/minecraft-lib.php';
        $row = $input['addLinkedAccount'];
        $type = strtolower(trim((string) ($row['type'] ?? 'java')));
        if (!in_array($type, ['java', 'bedrock', 'tlauncher'], true)) {
            $type = 'java';
        }
        $nick = trim((string) ($row['nick'] ?? ''));
        if ($nick === '') {
            gz_respond(400, ['ok' => false, 'message' => 'Informe o nick da conta']);
        }
        $linked = $user['linkedAccounts'] ?? [];
        if (!is_array($linked)) {
            $linked = [];
        }
        if (count($linked) >= 8) {
            gz_respond(400, ['ok' => false, 'message' => 'Máximo de 8 contas vinculadas']);
        }
        foreach ($linked as $ex) {
            if (strcasecmp((string) ($ex['nick'] ?? ''), $nick) === 0 && ($ex['type'] ?? '') === $type) {
                gz_respond(409, ['ok' => false, 'message' => 'Conta já vinculada']);
            }
        }
        $entry = ['type' => $type, 'nick' => $nick, 'uuid' => null, 'source' => $type];
        if ($type === 'bedrock') {
            $entry['nick'] = preg_replace('/\s+/', ' ', $nick) ?? $nick;
        } else {
            $look = gz_mc_lookup_nick($nick);
            if (empty($look['found'])) {
                gz_respond(400, ['ok' => false, 'message' => $look['message'] ?? 'Nick não encontrado']);
            }
            $entry['nick'] = (string) ($look['nick'] ?? $nick);
            $entry['uuid'] = $look['uuid'] ?? null;
            $entry['source'] = $look['source'] ?? $type;
        }
        $linked[] = $entry;
        $user['linkedAccounts'] = array_values($linked);
    }

    // Remover conta vinculada
    if (!empty($input['removeLinkedAccount'])) {
        $rm = $input['removeLinkedAccount'];
        $rmNick = is_array($rm) ? trim((string) ($rm['nick'] ?? '')) : trim((string) $rm);
        $rmType = is_array($rm) ? strtolower(trim((string) ($rm['type'] ?? ''))) : '';
        $linked = $user['linkedAccounts'] ?? [];
        if (!is_array($linked)) {
            $linked = [];
        }
        $user['linkedAccounts'] = array_values(array_filter($linked, static function ($ex) use ($rmNick, $rmType) {
            if (strcasecmp((string) ($ex['nick'] ?? ''), $rmNick) !== 0) {
                return true;
            }
            if ($rmType !== '' && ($ex['type'] ?? '') !== $rmType) {
                return true;
            }
            return false;
        }));
    }

    $user['updatedAt'] = date('c');
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
