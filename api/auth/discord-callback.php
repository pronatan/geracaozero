<?php
/**
 * Discord OAuth callback — login ou vínculo à conta logada
 */
require __DIR__ . '/common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$siteUrl = rtrim(gz_env('SITE_URL', 'https://geracaozero.ddnsfree.com'), '/');
$clientId = trim(gz_env('DISCORD_CLIENT_ID', ''));
$clientSecret = trim(gz_env('DISCORD_CLIENT_SECRET', ''));

function gz_discord_fail(string $msg, string $siteUrl): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem">' .
        '<h1>Discord</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>' .
        '<p><a href="/login">Login</a> · <a href="/conta">Conta</a></p></body></html>';
    exit;
}

if ($clientId === '' || $clientSecret === '') {
    gz_discord_fail('Discord OAuth não configurado.', $siteUrl);
}

$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');
$expected = (string) ($_SESSION['discord_oauth_state'] ?? '');
if ($code === '' || $state === '' || $expected === '' || !hash_equals($expected, $state)) {
    gz_discord_fail('State inválido. Tente de novo.', $siteUrl);
}
unset($_SESSION['discord_oauth_state']);
$linkUserId = (string) ($_SESSION['discord_oauth_link'] ?? '');
unset($_SESSION['discord_oauth_link']);

$redirect = $siteUrl . '/api/auth/discord-callback.php';
$tokenRes = null;
$ch = curl_init('https://discord.com/api/oauth2/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirect,
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT => 20,
]);
$raw = curl_exec($ch);
curl_close($ch);
$tokenRes = json_decode((string) $raw, true);
$access = (string) ($tokenRes['access_token'] ?? '');
if ($access === '') {
    gz_discord_fail('Falha ao obter token Discord.', $siteUrl);
}

$ch = curl_init('https://discord.com/api/users/@me');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access],
    CURLOPT_TIMEOUT => 20,
]);
$meRaw = curl_exec($ch);
curl_close($ch);
$me = json_decode((string) $meRaw, true);
$discordId = (string) ($me['id'] ?? '');
$discordUsername = trim((string) (($me['username'] ?? '') . (isset($me['discriminator']) && $me['discriminator'] !== '0' ? ('#' . $me['discriminator']) : '')));
if ($discordId === '') {
    gz_discord_fail('Não foi possível ler o usuário Discord.', $siteUrl);
}

// Procurar conta já vinculada
$existing = null;
foreach (gz_ddb_scan_all(gz_users_table(), 500) as $row) {
    if ((string) ($row['discordId'] ?? '') === $discordId) {
        $existing = $row;
        break;
    }
}

if ($linkUserId !== '') {
    $user = gz_find_user_by_id($linkUserId);
    if (!$user) {
        gz_discord_fail('Conta local não encontrada para vínculo.', $siteUrl);
    }
    if ($existing && ($existing['id'] ?? '') !== ($user['id'] ?? '')) {
        gz_discord_fail('Este Discord já está vinculado a outra conta.', $siteUrl);
    }
    $user['discordId'] = $discordId;
    $user['discordUsername'] = $discordUsername;
    $user['updatedAt'] = date('c');
    gz_save_user($user);
    header('Location: /conta?discord=linked');
    exit;
}

if ($existing) {
    $token = bin2hex(random_bytes(24));
    $existing['tokenHash'] = hash('sha256', $token);
    $existing['lastLoginAt'] = date('c');
    $existing['discordUsername'] = $discordUsername;
    gz_save_user($existing);
    gz_login_user($existing);
    // Passa token via cookie/session; front usa cookie session PHP + também localStorage via página ponte
    header('Location: /discord-done?token=' . urlencode($token));
    exit;
}

gz_discord_fail('Nenhuma conta vinculada a este Discord. Entre na sua conta do site e vincule em Minha conta.', $siteUrl);
