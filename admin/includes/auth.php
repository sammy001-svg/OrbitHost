<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/LoginGuard.php';
require_once __DIR__ . '/TOTP.php';

function auth_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('orbit_admin');
        session_start();
    }
}

/** Auto-migrate the 2FA columns onto admin_users (idempotent, once per request). */
function ensure_admin_2fa_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $col = db()->query("SHOW COLUMNS FROM admin_users LIKE 'totp_secret'")->fetch();
        if (!$col) {
            db()->exec("ALTER TABLE admin_users
                ADD COLUMN totp_secret       VARCHAR(64) DEFAULT NULL,
                ADD COLUMN totp_enabled      TINYINT(1)  NOT NULL DEFAULT 0,
                ADD COLUMN totp_backup_codes TEXT        DEFAULT NULL");
        }
    } catch (\Throwable $e) {
        // no ALTER privilege — 2FA simply won't be available until schema is added manually
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

/**
 * @return array{ok:bool, needs_2fa?:bool, message?:string}
 * ok=true, needs_2fa=true means the password was correct but the account
 * has 2FA enabled — auth_verify_2fa() must succeed before the session is
 * actually granted admin access (see the pending-marker check in
 * auth_check(), which does not treat a 2FA-pending session as logged in).
 */
function auth_login(string $email, string $password): array
{
    ensure_admin_2fa_columns();
    auth_start();

    $blocked = LoginGuard::checkBlocked('admin', $email);
    if ($blocked) return ['ok' => false, 'message' => $blocked];

    $stmt = db()->prepare('SELECT * FROM admin_users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $valid = $user && password_verify($password, $user['password']);
    LoginGuard::record('admin', $email, $valid);

    if (!$valid) return ['ok' => false, 'message' => 'Invalid credentials. Please check your email and password.'];

    if (!empty($user['totp_enabled'])) {
        session_regenerate_id(true);
        $_SESSION['admin_2fa_pending_id'] = $user['id'];
        return ['ok' => true, 'needs_2fa' => true];
    }

    auth_complete_login($user);
    return ['ok' => true];
}

/** Finish granting a session once password (and, if applicable, 2FA) both succeeded. */
function auth_complete_login(array $user): void
{
    session_regenerate_id(true);
    unset($_SESSION['admin_2fa_pending_id']);
    $_SESSION['admin_id']    = $user['id'];
    $_SESSION['admin_name']  = $user['name'];
    $_SESSION['admin_role']  = $user['role'];
    $_SESSION['admin_email'] = $user['email'];
    $_SESSION['last_active'] = time();
    db()->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
}

/**
 * @return array{ok:bool, message?:string}
 * Completes a login that's pending on auth_login()'s needs_2fa result.
 * Accepts either a live 6-digit TOTP code or a one-time backup code.
 */
function auth_verify_2fa(string $code): array
{
    auth_start();
    $pendingId = (int) ($_SESSION['admin_2fa_pending_id'] ?? 0);
    if (!$pendingId) return ['ok' => false, 'message' => 'No sign-in in progress.'];

    $blocked = LoginGuard::checkBlocked('admin_2fa', (string) $pendingId);
    if ($blocked) return ['ok' => false, 'message' => $blocked];

    $stmt = db()->prepare('SELECT * FROM admin_users WHERE id = ? LIMIT 1');
    $stmt->execute([$pendingId]);
    $user = $stmt->fetch();
    if (!$user || empty($user['totp_enabled'])) return ['ok' => false, 'message' => 'Two-factor session expired — please sign in again.'];

    $code = trim($code);
    $ok   = TOTP::verify((string) $user['totp_secret'], $code);

    if (!$ok) {
        // Try the code as a one-time backup code instead of a live TOTP.
        $backups = json_decode((string) ($user['totp_backup_codes'] ?? '[]'), true) ?: [];
        foreach ($backups as $i => $hash) {
            if (password_verify($code, $hash)) {
                unset($backups[$i]);
                db()->prepare('UPDATE admin_users SET totp_backup_codes = ? WHERE id = ?')
                    ->execute([json_encode(array_values($backups)), $user['id']]);
                $ok = true;
                break;
            }
        }
    }

    LoginGuard::record('admin_2fa', (string) $pendingId, $ok);
    if (!$ok) return ['ok' => false, 'message' => 'Incorrect code. Please try again.'];

    auth_complete_login($user);
    return ['ok' => true];
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
    if (!empty($_SESSION['admin_id']) && empty($_SESSION['admin_email'])) {
        try {
            $stmt = db()->prepare('SELECT email FROM admin_users WHERE id = ? LIMIT 1');
            $stmt->execute([$_SESSION['admin_id']]);
            $user = $stmt->fetch();
            if ($user) {
                $_SESSION['admin_email'] = $user['email'];
            }
        } catch (Exception $e) {
            // Silently fallback if DB is not available
        }
    }

    return [
        'id'    => $_SESSION['admin_id']    ?? 0,
        'name'  => $_SESSION['admin_name']  ?? 'Admin',
        'role'  => $_SESSION['admin_role']  ?? 'admin',
        'email' => $_SESSION['admin_email'] ?? '',
    ];
}

function can(string $role): bool
{
    $hierarchy = ['support' => 1, 'admin' => 2, 'super_admin' => 3];
    $mine = $hierarchy[$_SESSION['admin_role'] ?? 'support'] ?? 1;
    $need = $hierarchy[$role] ?? 1;
    return $mine >= $need;
}
