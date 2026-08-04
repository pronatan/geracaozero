<?php
/**
 * Discord helpers — DM via bot (2FA) quando DISCORD_BOT_TOKEN estiver definido
 */
require_once __DIR__ . '/bootstrap.php';

function gz_discord_bot_token(): string
{
    return trim(gz_env('DISCORD_BOT_TOKEN', ''));
}

function gz_discord_configured(): bool
{
    return trim(gz_env('DISCORD_CLIENT_ID', '')) !== ''
        && trim(gz_env('DISCORD_CLIENT_SECRET', '')) !== '';
}

/**
 * Envia DM para um usuário Discord (precisa de bot no servidor + permissão DM).
 * @return array{ok:bool,message:string}
 */
function gz_discord_send_dm(string $discordUserId, string $content): array
{
    $token = gz_discord_bot_token();
    if ($token === '') {
        return ['ok' => false, 'message' => 'DISCORD_BOT_TOKEN não configurado'];
    }
    $discordUserId = preg_replace('/\D/', '', $discordUserId) ?? '';
    if ($discordUserId === '') {
        return ['ok' => false, 'message' => 'discordId inválido'];
    }

    // 1) Abrir canal DM
    $ch = curl_init('https://discord.com/api/v10/users/@me/channels');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bot ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['recipient_id' => $discordUserId]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $chan = json_decode((string) $raw, true);
    $channelId = (string) ($chan['id'] ?? '');
    if ($channelId === '' || $status < 200 || $status >= 300) {
        $msg = is_array($chan) ? ($chan['message'] ?? 'Falha ao abrir DM') : 'Falha ao abrir DM';
        return ['ok' => false, 'message' => (string) $msg];
    }

    // 2) Enviar mensagem
    $ch = curl_init('https://discord.com/api/v10/channels/' . rawurlencode($channelId) . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bot ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['content' => $content], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw2 = curl_exec($ch);
    $status2 = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status2 < 200 || $status2 >= 300) {
        $body = json_decode((string) $raw2, true);
        $msg = is_array($body) ? ($body['message'] ?? 'Falha ao enviar DM') : 'Falha ao enviar DM';
        return ['ok' => false, 'message' => (string) $msg];
    }
    return ['ok' => true, 'message' => 'DM enviada'];
}
