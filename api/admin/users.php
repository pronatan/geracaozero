<?php
/**
 * Admin: usuários
 * GET    — listar
 * POST   — criar (user ou admin)
 * PUT    — atualizar role/senha/nick/email
 * DELETE — ?id=  remover
 */
require dirname(__DIR__) . '/auth/common.php';

$admin = gz_require_admin();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $users = gz_ddb_scan_all(gz_users_table(), 500);
    usort($users, static function ($a, $b) {
        return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
    });
    gz_respond(200, [
        'ok' => true,
        'users' => array_map('gz_admin_user_view', $users),
    ]);
}

if ($method === 'POST') {
    $input = gz_json_input();
    $nick = trim((string) ($input['nick'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');
    $role = strtolower(trim((string) ($input['role'] ?? 'user')));
    if (!in_array($role, ['user', 'admin'], true)) {
        gz_respond(400, ['ok' => false, 'message' => 'Role inválida (user|admin)']);
    }
    if (strlen($nick) < 3 || strlen($nick) > 32 || !preg_match('/^[a-zA-Z0-9_]+$/', $nick)) {
        gz_respond(400, ['ok' => false, 'message' => 'Nome inválido']);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        gz_respond(400, ['ok' => false, 'message' => 'E-mail inválido']);
    }
    if (strlen($password) < 6) {
        gz_respond(400, ['ok' => false, 'message' => 'Senha mínima 6 caracteres']);
    }
    if (gz_find_user_by_email($email) || gz_find_user_by_nick($nick)) {
        gz_respond(409, ['ok' => false, 'message' => 'Nome ou e-mail já cadastrado']);
    }

    $user = [
        'id' => bin2hex(random_bytes(8)),
        'nick' => $nick,
        'email' => $email,
        'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'createdAt' => date('c'),
        'createdBy' => $admin['id'] ?? null,
    ];
    if (!gz_save_user($user)) {
        gz_respond(500, ['ok' => false, 'message' => 'Falha ao salvar usuário']);
    }
    gz_log('admin.log', ['action' => 'create_user', 'by' => $admin['nick'] ?? '', 'user' => $user['nick'], 'role' => $role]);
    gz_respond(201, ['ok' => true, 'user' => gz_admin_user_view($user)]);
}

if ($method === 'PUT' || $method === 'PATCH') {
    $input = gz_json_input();
    $id = trim((string) ($input['id'] ?? ''));
    if ($id === '') {
        gz_respond(400, ['ok' => false, 'message' => 'id obrigatório']);
    }
    $user = gz_find_user_by_id($id);
    if (!$user) {
        gz_respond(404, ['ok' => false, 'message' => 'Usuário não encontrado']);
    }

    if (isset($input['role'])) {
        $role = strtolower(trim((string) $input['role']));
        if (!in_array($role, ['user', 'admin'], true)) {
            gz_respond(400, ['ok' => false, 'message' => 'Role inválida']);
        }
        // impedir auto-rebaixamento se for o único admin
        if ($user['id'] === ($admin['id'] ?? '') && $role !== 'admin') {
            gz_respond(400, ['ok' => false, 'message' => 'Você não pode remover seu próprio admin']);
        }
        $user['role'] = $role;
    }
    if (!empty($input['nick'])) {
        $nick = trim((string) $input['nick']);
        if (strlen($nick) < 3 || !preg_match('/^[a-zA-Z0-9_]+$/', $nick)) {
            gz_respond(400, ['ok' => false, 'message' => 'Nome inválido']);
        }
        $other = gz_find_user_by_nick($nick);
        if ($other && ($other['id'] ?? '') !== $id) {
            gz_respond(409, ['ok' => false, 'message' => 'Nome em uso']);
        }
        $user['nick'] = $nick;
    }
    if (!empty($input['email'])) {
        $email = strtolower(trim((string) $input['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            gz_respond(400, ['ok' => false, 'message' => 'E-mail inválido']);
        }
        $other = gz_find_user_by_email($email);
        if ($other && ($other['id'] ?? '') !== $id) {
            gz_respond(409, ['ok' => false, 'message' => 'E-mail em uso']);
        }
        $user['email'] = $email;
    }
    if (!empty($input['password'])) {
        if (strlen((string) $input['password']) < 6) {
            gz_respond(400, ['ok' => false, 'message' => 'Senha mínima 6 caracteres']);
        }
        $user['passwordHash'] = password_hash((string) $input['password'], PASSWORD_DEFAULT);
        $user['tokenHash'] = 'revoked_' . bin2hex(random_bytes(4));
    }

    if (!gz_save_user($user)) {
        gz_respond(500, ['ok' => false, 'message' => 'Falha ao atualizar']);
    }
    gz_log('admin.log', ['action' => 'update_user', 'by' => $admin['nick'] ?? '', 'userId' => $id]);
    gz_respond(200, ['ok' => true, 'user' => gz_admin_user_view($user)]);
}

if ($method === 'DELETE') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        $input = gz_json_input();
        $id = trim((string) ($input['id'] ?? ''));
    }
    if ($id === '') {
        gz_respond(400, ['ok' => false, 'message' => 'id obrigatório']);
    }
    if ($id === ($admin['id'] ?? '')) {
        gz_respond(400, ['ok' => false, 'message' => 'Não é possível excluir a si mesmo']);
    }
    $user = gz_find_user_by_id($id);
    if (!$user) {
        gz_respond(404, ['ok' => false, 'message' => 'Usuário não encontrado']);
    }
    $res = gz_ddb_delete(gz_users_table(), ['id' => $id]);
    if (!$res['ok']) {
        gz_respond(500, ['ok' => false, 'message' => 'Falha ao excluir', 'details' => $res['body']]);
    }
    gz_log('admin.log', ['action' => 'delete_user', 'by' => $admin['nick'] ?? '', 'userId' => $id]);
    gz_respond(200, ['ok' => true]);
}

gz_respond(405, ['ok' => false, 'message' => 'Método não permitido']);
