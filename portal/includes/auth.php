<?php
require_once __DIR__ . '/../../admin/includes/db.php';
require_once __DIR__ . '/../../admin/includes/LoginGuard.php';

function portal_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name(PORTAL_SESSION);
        session_start();
    }
}

function portal_check(): void
{
    portal_start();
    if (empty($_SESSION['client_id'])) {
        header('Location: ' . PORTAL_URL . '/login.php');
        exit;
    }
    if (!empty($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        header('Location: ' . PORTAL_URL . '/login.php?timeout=1');
        exit;
    }
    $_SESSION['last_active'] = time();
}

/** @return array{ok:bool, message?:string} */
function portal_login(string $email, string $password): array
{
    $blocked = LoginGuard::checkBlocked('portal', $email);
    if ($blocked) return ['ok' => false, 'message' => $blocked];

    $stmt = db()->prepare('SELECT id, first_name, last_name, status, portal_password FROM clients WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $client = $stmt->fetch();

    $valid = $client && $client['status'] === 'active' && $client['portal_password']
          && password_verify($password, $client['portal_password']);
    LoginGuard::record('portal', $email, (bool) $valid);

    if (!$valid) {
        return ['ok' => false, 'message' => 'Invalid email or password. If you have not set a portal password yet, please use the link in your welcome email.'];
    }

    session_regenerate_id(true);
    $_SESSION['client_id']    = $client['id'];
    $_SESSION['client_name']  = $client['first_name'] . ' ' . $client['last_name'];
    $_SESSION['client_email'] = $email;
    $_SESSION['last_active']  = time();

    db()->prepare('UPDATE clients SET portal_login = NOW() WHERE id = ?')->execute([$client['id']]);
    return ['ok' => true];
}

function portal_csrf(): string
{
    portal_start();
    if (empty($_SESSION['portal_csrf'])) {
        $_SESSION['portal_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['portal_csrf'];
}

function portal_csrf_verify(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['portal_csrf'] ?? '', $_POST['csrf_token'])) {
            http_response_code(403);
            die('Request verification failed. Please go back and try again.');
        }
    }
}

function current_client(): array
{
    return [
        'id'    => $_SESSION['client_id']    ?? 0,
        'name'  => $_SESSION['client_name']  ?? '',
        'email' => $_SESSION['client_email'] ?? '',
    ];
}

function portal_flash_set(string $type, string $msg): void
{
    portal_start();
    $_SESSION['portal_flash'] = compact('type', 'msg');
}

function portal_flash_get(): ?array
{
    $f = $_SESSION['portal_flash'] ?? null;
    unset($_SESSION['portal_flash']);
    return $f;
}
