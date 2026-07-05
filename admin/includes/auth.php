<?php
require_once __DIR__ . '/db.php';

function auth_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('orbit_admin');
        session_start();
    }
}

function auth_check(): void
{
    auth_start();
    if (empty($_SESSION['admin_id'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
    // Idle timeout
    if (!empty($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        header('Location: ' . APP_URL . '/login.php?timeout=1');
        exit;
    }
    $_SESSION['last_active'] = time();
}

function auth_login(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id']    = $user['id'];
        $_SESSION['admin_name']  = $user['name'];
        $_SESSION['admin_role']  = $user['role'];
        $_SESSION['last_active'] = time();
        db()->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = ?')
            ->execute([$user['id']]);
        return true;
    }
    return false;
}

function csrf_token(): string
{
    auth_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            http_response_code(403);
            die('CSRF token mismatch. Go back and try again.');
        }
    }
}

function current_admin(): array
{
    return [
        'id'   => $_SESSION['admin_id']   ?? 0,
        'name' => $_SESSION['admin_name'] ?? 'Admin',
        'role' => $_SESSION['admin_role'] ?? 'admin',
    ];
}

function can(string $role): bool
{
    $hierarchy = ['support' => 1, 'admin' => 2, 'super_admin' => 3];
    $mine = $hierarchy[$_SESSION['admin_role'] ?? 'support'] ?? 1;
    $need = $hierarchy[$role] ?? 1;
    return $mine >= $need;
}
