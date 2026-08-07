<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/dynamodb.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function gz_find_user_by_email(string $email): ?array
{
    $email = strtolower(trim($email));
    $items = gz_ddb_query_eq(gz_users_table(), 'email-index', 'email', $email);
    return $items[0] ?? null;
}

function gz_find_user_by_nick(string $nick): ?array
{
    $nickLower = strtolower(trim($nick));
    $items = gz_ddb_query_eq(gz_users_table(), 'nick-index', 'nickLower', $nickLower);
    return $items[0] ?? null;
}

function gz_find_user_by_id(string $id): ?array
{
    return gz_ddb_get(gz_users_table(), ['id' => $id]);
}

function gz_save_user(array $user): bool
{
    $user['nickLower'] = strtolower((string) ($user['nick'] ?? ''));
    $user['email'] = strtolower((string) ($user['email'] ?? ''));
    $res = gz_ddb_put(gz_users_table(), $user);
    if (!$res['ok']) {
        gz_log('auth.log', ['action' => 'save_user_fail', 'body' => $res['body']]);
    }
    return $res['ok'];
}

function gz_user_avatar_url(array $user): ?string
{
    $custom = trim((string) ($user['avatar'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }
    $uuid = trim((string) ($user['mcUuid'] ?? ''));
    if ($uuid !== '') {
        return 'https://mc-heads.net/avatar/' . rawurlencode($uuid) . '/64';
    }
    $nick = trim((string) ($user['nick'] ?? ''));
    if ($nick !== '') {
        return 'https://mc-heads.net/avatar/' . rawurlencode($nick) . '/64';
    }
    return null;
}

function gz_public_user(array $user): array
{
    $linked = $user['linkedAccounts'] ?? [];
    if (!is_array($linked)) {
        $linked = [];
    }
    return [
        'id' => $user['id'] ?? null,
        'nick' => $user['nick'] ?? '',
        'email' => $user['email'] ?? '',
        'role' => $user['role'] ?? 'user',
        'avatar' => gz_user_avatar_url($user),
        'mcUuid' => $user['mcUuid'] ?? null,
        'mcSource' => $user['mcSource'] ?? null,
        'linkedAccounts' => array_values($linked),
        'twoFactorEnabled' => !empty($user['twoFactorEnabled']),
        'twoFactorChannel' => (string) ($user['twoFactorChannel'] ?? 'email'),
        'discordId' => $user['discordId'] ?? null,
        'discordUsername' => $user['discordUsername'] ?? null,
        'discordOAuthReady' => trim(gz_env('DISCORD_BOT_TOKEN', '')) !== ''
            || (trim(gz_env('DISCORD_CLIENT_ID', '')) !== '' && trim(gz_env('DISCORD_CLIENT_SECRET', '')) !== ''),
        'createdAt' => $user['createdAt'] ?? null,
    ];
}

function gz_user_is_admin(?array $user): bool
{
    return $user && (($user['role'] ?? '') === 'admin');
}

function gz_require_admin(): array
{
    $user = gz_current_user();
    if (!$user) {
        gz_respond(401, ['ok' => false, 'message' => 'Faça login como administrador']);
    }
    if (!gz_user_is_admin($user)) {
        gz_respond(403, ['ok' => false, 'message' => 'Acesso restrito a administradores']);
    }
    return $user;
}

function gz_admin_user_view(array $user): array
{
    return [
        'id' => $user['id'] ?? null,
        'nick' => $user['nick'] ?? '',
        'email' => $user['email'] ?? '',
        'role' => $user['role'] ?? 'user',
        'avatar' => gz_user_avatar_url($user),
        'mcUuid' => $user['mcUuid'] ?? null,
        'mcSource' => $user['mcSource'] ?? null,
        'createdAt' => $user['createdAt'] ?? null,
        'lastLoginAt' => $user['lastLoginAt'] ?? null,
    ];
}

function gz_current_user(): ?array
{
    // Token Bearer (front apontando pra API AWS)
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        $auth = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
    }
    if (stripos($auth, 'Bearer ') === 0) {
        $token = trim(substr($auth, 7));
        if ($token !== '') {
            $hash = hash('sha256', $token);
            // Scan is avoided: query by scanning users is expensive; store token lookup via email not available.
            // Fallback: if session exists use it; for token we scan limited via filter expression on tokenHash.
            $res = gz_dynamo_request('DynamoDB_20120810.Scan', [
                'TableName' => gz_users_table(),
                'FilterExpression' => 'tokenHash = :h',
                'ExpressionAttributeValues' => [':h' => ['S' => $hash]],
                'Limit' => 50,
            ]);
            if ($res['ok'] && !empty($res['body']['Items'][0])) {
                return gz_item_to_php($res['body']['Items'][0]);
            }
        }
    }

    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return gz_find_user_by_id((string) $_SESSION['user_id']);
}

function gz_login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_nick'] = $user['nick'];
    $_SESSION['user_email'] = $user['email'];
}

function gz_logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function gz_save_order(array $order): bool
{
    if (empty($order['id'])) {
        $order['id'] = $order['orderId'] ?? bin2hex(random_bytes(8));
    }
    if (empty($order['createdAt'])) {
        $order['createdAt'] = date('c');
    }
    if (empty($order['userId'])) {
        $order['userId'] = 'guest';
    }
    $res = gz_ddb_put(gz_orders_table(), $order);
    if (!$res['ok']) {
        gz_log('orders-ddb.log', ['action' => 'save_order_fail', 'body' => $res['body'], 'order' => $order]);
    }
    return $res['ok'];
}
