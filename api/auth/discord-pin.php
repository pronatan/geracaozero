<?php
/**
 * Discord por código na DM (sem OAuth redirect — Discord não grava redirect_uris via bot API)
 *
 * POST JSON:
 *  { "action":"send", "discordId":"123...", "mode":"login"|"link" }
 *  { "action":"confirm", "discordId":"123...", "code":"123456", "mode":"login"|"link" }
 */
require __DIR__ . '/common.php';
require_once dirname(__DIR__) . '/discord.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
}

if (gz_discord_bot_token() === '') {
    gz_respond(503, ['ok' => false, 'message' => 'Bot Discord não configurado']);
}

$input = gz_json_input();
$action = strtolower(trim((string) ($input['action'] ?? '')));
$mode = strtolower(trim((string) ($input['mode'] ?? 'login')));
if (!in_array($mode, ['login', 'link'], true)) {
    $mode = 'login';
}
$discordId = preg_replace('/\D/', '', (string) ($input['discordId'] ?? '')) ?? '';
$code = trim((string) ($input['code'] ?? ''));

function gz_find_user_by_discord_id(string $discordId): ?array
{
    if ($discordId === '') {
        return null;
    }
    foreach (gz_ddb_scan_all(gz_users_table(), 500) as $row) {
        if ((string) ($row['discordId'] ?? '') === $discordId) {
            return $row;
        }
    }
    return null;
}

function gz_discord_invite_url(): string
{
    $clientId = trim(gz_env('DISCORD_CLIENT_ID', ''));
    if ($clientId === '') {
        return 'https://discord.gg/pAtKdPHBk2';
    }
    return 'https://discord.com/api/oauth2/authorize?client_id=' . rawurlencode($clientId) .
        '&permissions=8&scope=bot%20applications.commands';
}

if ($action === 'send') {
    if ($discordId === '' || strlen($discordId) < 15) {
        gz_respond(400, [
            'ok' => false,
            'message' => 'Informe seu ID do Discord (Ativar Modo Desenvolvedor → clique direito no seu usuário → Copiar ID).',
        ]);
    }

    $pin = (string) random_int(100000, 999999);
    $pinHash = hash('sha256', $pin);
    $expires = date('c', time() + 600);

    if ($mode === 'link') {
        $user = gz_current_user();
        if (!$user) {
            gz_respond(401, ['ok' => false, 'message' => 'Faça login no site para vincular o Discord']);
        }
        $other = gz_find_user_by_discord_id($discordId);
        if ($other && ($other['id'] ?? '') !== ($user['id'] ?? '')) {
            gz_respond(409, ['ok' => false, 'message' => 'Este Discord já está vinculado a outra conta']);
        }
        $user['discordPinHash'] = $pinHash;
        $user['discordPinExpires'] = $expires;
        $user['discordPinPendingId'] = $discordId;
        $user['updatedAt'] = date('c');
        if (!gz_save_user($user)) {
            gz_respond(500, ['ok' => false, 'message' => 'Falha ao salvar']);
        }
        $nick = (string) ($user['nick'] ?? '');
        $sent = gz_discord_send_dm(
            $discordId,
            "**Geração Zero** — vínculo de conta\nOlá **{$nick}**!\nSeu código: `{$pin}`\nVálido por 10 minutos.\nSe não foi você, ignore."
        );
    } else {
        // login: precisa já estar vinculado
        $user = gz_find_user_by_discord_id($discordId);
        if (!$user) {
            gz_respond(404, [
                'ok' => false,
                'message' => 'Nenhuma conta vinculada a este Discord. Entre no site e vincule em Minha conta.',
                'inviteUrl' => gz_discord_invite_url(),
            ]);
        }
        $user['discordPinHash'] = $pinHash;
        $user['discordPinExpires'] = $expires;
        $user['discordPinPendingId'] = $discordId;
        $user['updatedAt'] = date('c');
        if (!gz_save_user($user)) {
            gz_respond(500, ['ok' => false, 'message' => 'Falha ao salvar']);
        }
        $nick = (string) ($user['nick'] ?? '');
        $sent = gz_discord_send_dm(
            $discordId,
            "**Geração Zero** — login\nOlá **{$nick}**!\nSeu código: `{$pin}`\nVálido por 10 minutos."
        );
    }

    if (empty($sent['ok'])) {
        gz_respond(502, [
            'ok' => false,
            'message' => 'Não foi possível enviar a DM. Entre no servidor do Geração Zero, libere DMs do servidor e tente de novo. ' .
                ($sent['message'] ?? ''),
            'inviteUrl' => gz_discord_invite_url(),
        ]);
    }

    gz_respond(200, [
        'ok' => true,
        'message' => 'Enviamos um código na sua DM do Discord.',
        'expiresIn' => 600,
    ]);
}

if ($action === 'confirm') {
    if ($discordId === '' || $code === '') {
        gz_respond(400, ['ok' => false, 'message' => 'Informe Discord ID e código']);
    }

    if ($mode === 'link') {
        $user = gz_current_user();
        if (!$user) {
            gz_respond(401, ['ok' => false, 'message' => 'Faça login no site']);
        }
        $exp = strtotime((string) ($user['discordPinExpires'] ?? ''));
        if (!$exp || $exp < time()) {
            gz_respond(401, ['ok' => false, 'message' => 'Código expirado. Peça outro.']);
        }
        if ((string) ($user['discordPinPendingId'] ?? '') !== $discordId) {
            gz_respond(400, ['ok' => false, 'message' => 'Discord ID não confere com o pedido']);
        }
        if (!hash_equals((string) ($user['discordPinHash'] ?? ''), hash('sha256', $code))) {
            gz_respond(401, ['ok' => false, 'message' => 'Código inválido']);
        }
        // username opcional via API
        $username = '';
        $token = gz_discord_bot_token();
        $ch = curl_init('https://discord.com/api/v10/users/' . rawurlencode($discordId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bot ' . $token],
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $du = json_decode((string) $raw, true);
        if (!empty($du['username'])) {
            $username = (string) $du['username'];
            if (!empty($du['discriminator']) && $du['discriminator'] !== '0') {
                $username .= '#' . $du['discriminator'];
            }
        }

        $user['discordId'] = $discordId;
        $user['discordUsername'] = $username !== '' ? $username : $discordId;
        unset($user['discordPinHash'], $user['discordPinExpires'], $user['discordPinPendingId']);
        $user['updatedAt'] = date('c');
        if (!gz_save_user($user)) {
            gz_respond(500, ['ok' => false, 'message' => 'Falha ao salvar vínculo']);
        }
        gz_respond(200, [
            'ok' => true,
            'message' => 'Discord vinculado!',
            'user' => gz_public_user($user),
        ]);
    }

    // login
    $user = gz_find_user_by_discord_id($discordId);
    if (!$user) {
        gz_respond(404, ['ok' => false, 'message' => 'Conta não encontrada para este Discord']);
    }
    $exp = strtotime((string) ($user['discordPinExpires'] ?? ''));
    if (!$exp || $exp < time()) {
        gz_respond(401, ['ok' => false, 'message' => 'Código expirado. Peça outro.']);
    }
    if ((string) ($user['discordPinPendingId'] ?? '') !== $discordId) {
        gz_respond(400, ['ok' => false, 'message' => 'Discord ID não confere']);
    }
    if (!hash_equals((string) ($user['discordPinHash'] ?? ''), hash('sha256', $code))) {
        gz_respond(401, ['ok' => false, 'message' => 'Código inválido']);
    }

    unset($user['discordPinHash'], $user['discordPinExpires'], $user['discordPinPendingId']);
    $token = bin2hex(random_bytes(24));
    $user['tokenHash'] = hash('sha256', $token);
    $user['lastLoginAt'] = date('c');
    $user['updatedAt'] = date('c');
    gz_save_user($user);
    gz_login_user($user);

    gz_respond(200, [
        'ok' => true,
        'message' => 'Login realizado',
        'token' => $token,
        'user' => gz_public_user($user),
    ]);
}

gz_respond(400, ['ok' => false, 'message' => 'Ação inválida (send|confirm)']);
