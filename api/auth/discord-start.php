<?php
/**
 * Discord — inicia fluxo.
 * OAuth redirect_uris NÃO pode ser gravado via Bot API (Discord ignora).
 * Por isso usamos login/vínculo por código na DM: /discord-auth
 *
 * Se DISCORD_OAUTH_ENABLED=true E redirects já cadastrados no portal, usa OAuth clássico.
 */
require __DIR__ . '/common.php';

$oauthEnabled = in_array(strtolower(trim(gz_env('DISCORD_OAUTH_ENABLED', 'false'))), ['1', 'true', 'yes'], true);
$clientId = trim(gz_env('DISCORD_CLIENT_ID', ''));
$siteUrl = rtrim(gz_env('SITE_URL', 'https://geracaozero.ddnsfree.com'), '/');

$mode = 'login';
$user = gz_current_user();
$qToken = trim((string) ($_GET['token'] ?? ''));
if (!$user && $qToken !== '') {
    $hash = hash('sha256', $qToken);
    $res = gz_dynamo_request('DynamoDB_20120810.Scan', [
        'TableName' => gz_users_table(),
        'FilterExpression' => 'tokenHash = :h',
        'ExpressionAttributeValues' => [':h' => ['S' => $hash]],
        'Limit' => 50,
    ]);
    if (!empty($res['ok']) && !empty($res['body']['Items'][0])) {
        $user = gz_item_to_php($res['body']['Items'][0]);
    }
}
if ($user || isset($_GET['link']) || $qToken !== '') {
    $mode = 'link';
}

// Fluxo por DM (padrão — funciona via API sem portal OAuth redirects)
if (!$oauthEnabled) {
    $qs = 'mode=' . rawurlencode($mode);
    header('Location: /discord-auth?' . $qs);
    exit;
}

if ($clientId === '') {
    header('Location: /discord-auth?mode=' . rawurlencode($mode));
    exit;
}

$state = bin2hex(random_bytes(16));
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['discord_oauth_state'] = $state;
$_SESSION['discord_oauth_link'] = $user ? (string) ($user['id'] ?? '') : '';

$redirect = $siteUrl . '/api/auth/discord-callback.php';
$params = http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirect,
    'response_type' => 'code',
    'scope' => 'identify email',
    'state' => $state,
    'prompt' => 'consent',
]);

header('Location: https://discord.com/api/oauth2/authorize?' . $params);
exit;
