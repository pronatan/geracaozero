<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function gz_users_file(): string
{
    $dir = dirname(__DIR__, 2) . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/users.json';
    if (!is_file($file)) {
        file_put_contents($file, "[]\n", LOCK_EX);
    }
    return $file;
}

function gz_users_read(): array
{
    $raw = @file_get_contents(gz_users_file());
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function gz_users_write(array $users): bool
{
    $json = json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return file_put_contents(gz_users_file(), $json . "\n", LOCK_EX) !== false;
}

function gz_find_user_by_email(string $email): ?array
{
    $email = strtolower(trim($email));
    foreach (gz_users_read() as $user) {
        if (isset($user['email']) && strtolower($user['email']) === $email) {
            return $user;
        }
    }
    return null;
}

function gz_find_user_by_nick(string $nick): ?array
{
    $nick = strtolower(trim($nick));
    foreach (gz_users_read() as $user) {
        if (isset($user['nick']) && strtolower($user['nick']) === $nick) {
            return $user;
        }
    }
    return null;
}

function gz_public_user(array $user): array
{
    return [
        'id' => $user['id'] ?? null,
        'nick' => $user['nick'] ?? '',
        'email' => $user['email'] ?? '',
        'createdAt' => $user['createdAt'] ?? null,
    ];
}

function gz_current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $id = (string) $_SESSION['user_id'];
    foreach (gz_users_read() as $user) {
        if ((string) ($user['id'] ?? '') === $id) {
            return $user;
        }
    }
    return null;
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
