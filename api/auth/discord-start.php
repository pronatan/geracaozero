<?php
/**
 * Discord OAuth — inicia login/vínculo
 * Env: DISCORD_CLIENT_ID, DISCORD_CLIENT_SECRET, SITE_URL
 */
require __DIR__ . '/common.php';

$clientId = trim(gz_env('DISCORD_CLIENT_ID', ''));
$siteUrl = rtrim(gz_env('SITE_URL', 'https://geracaozero.ddnsfree.com'), '/');
if ($clientId === '') {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem">' .
        '<h1>Discord não configurado</h1>' .
        '<p>Defina <code>DISCORD_CLIENT_ID</code> e <code>DISCORD_CLIENT_SECRET</code> no ambiente EB.</p>' .
        '<p><a href="/conta">Voltar</a></p></body></html>';
    exit;
}

$state = bin2hex(random_bytes(16));
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['discord_oauth_state'] = $state;
$user = gz_current_user();
// Front guarda token no localStorage — link <a> não manda Authorization
if (!$user) {
    $qToken = trim((string) ($_GET['token'] ?? ''));
    if ($qToken !== '') {
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
}
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
