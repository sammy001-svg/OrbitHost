<?php
require_once __DIR__ . '/../../admin/includes/db.php';
require_once __DIR__ . '/../../admin/includes/LoginGuard.php';
require_once __DIR__ . '/../../admin/includes/TOTP.php';

function portal_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name(PORTAL_SESSION);
        session_start();
    }
}

/** Auto-migrate the 2FA columns onto clients (idempotent, once per request). */
function ensure_client_2fa_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $col = db()->query("SHOW COLUMNS FROM clients LIKE 'totp_secret'")->fetch();
        if (!$col) {
            db()->exec("ALTER TABLE clients
                ADD COLUMN totp_secret       VARCHAR(64) DEFAULT NULL,
                ADD COLUMN totp_enabled      TINYINT(1)  NOT NULL DEFAULT 0,
                ADD COLUMN totp_backup_codes TEXT        DEFAULT NULL");
        }
    } catch (\Throwable $e) {
        // no ALTER privilege — 2FA simply won't be available until schema is added manually
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

/**
 * @return array{ok:bool, needs_2fa?:bool, message?:string}
 * ok=true, needs_2fa=true means the password was correct but the account
 * has 2FA enabled — portal_verify_2fa() must succeed before the session
 * is actually granted (portal_check() does not treat a 2FA-pending
 * session as logged in, since client_id is never set until then).
 */
function portal_login(string $email, string $password): array
{
    ensure_client_2fa_columns();

    $blocked = LoginGuard::checkBlocked('portal', $email);
    if ($blocked) return ['ok' => false, 'message' => $blocked];

    $stmt = db()->prepare('SELECT id, first_name, last_name, email, status, portal_password, totp_enabled FROM clients WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $client = $stmt->fetch();

    $valid = $client && $client['status'] === 'active' && $client['portal_password']
          && password_verify($password, $client['portal_password']);
    LoginGuard::record('portal', $email, (bool) $valid);

    if (!$valid) {
        return ['ok' => false, 'message' => 'Invalid email or password. If you have not set a portal password yet, please use the link in your welcome email.'];
    }

    if (!empty($client['totp_enabled'])) {
        session_regenerate_id(true);
        $_SESSION['client_2fa_pending_id'] = $client['id'];
        return ['ok' => true, 'needs_2fa' => true];
    }

    portal_complete_login($client);
    return ['ok' => true];
}

/** Finish granting a session once password (and, if applicable, 2FA) both succeeded. */
function portal_complete_login(array $client): void
{
    session_regenerate_id(true);
    unset($_SESSION['client_2fa_pending_id']);
    $_SESSION['client_id']    = $client['id'];
    $_SESSION['client_name']  = trim($client['first_name'] . ' ' . $client['last_name']);
    $_SESSION['client_email'] = $client['email'];
    $_SESSION['last_active']  = time();

    db()->prepare('UPDATE clients SET portal_login = NOW() WHERE id = ?')->execute([$client['id']]);
}

/**
 * @return array{ok:bool, message?:string}
 * Completes a login that's pending on portal_login()'s needs_2fa result.
 * Accepts either a live 6-digit TOTP code or a one-time backup code.
 */
function portal_verify_2fa(string $code): array
{
    portal_start();
    $pendingId = (int) ($_SESSION['client_2fa_pending_id'] ?? 0);
    if (!$pendingId) return ['ok' => false, 'message' => 'No sign-in in progress.'];

    $blocked = LoginGuard::checkBlocked('portal_2fa', (string) $pendingId);
    if ($blocked) return ['ok' => false, 'message' => $blocked];

    $stmt = db()->prepare('SELECT * FROM clients WHERE id = ? LIMIT 1');
    $stmt->execute([$pendingId]);
    $client = $stmt->fetch();
    if (!$client || empty($client['totp_enabled'])) return ['ok' => false, 'message' => 'Two-factor session expired — please sign in again.'];

    $code = trim($code);
    $ok   = TOTP::verify((string) $client['totp_secret'], $code);

    if (!$ok) {
        // Try the code as a one-time backup code instead of a live TOTP.
        $backups = json_decode((string) ($client['totp_backup_codes'] ?? '[]'), true) ?: [];
        foreach ($backups as $i => $hash) {
            if (password_verify($code, $hash)) {
                unset($backups[$i]);
                db()->prepare('UPDATE clients SET totp_backup_codes = ? WHERE id = ?')
                    ->execute([json_encode(array_values($backups)), $client['id']]);
                $ok = true;
                break;
            }
        }
    }

    LoginGuard::record('portal_2fa', (string) $pendingId, $ok);
    if (!$ok) return ['ok' => false, 'message' => 'Incorrect code. Please try again.'];

    portal_complete_login($client);
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

/** Where a just-authenticated client should land (checkout, etc.) — whitelist of portal pages only. */
function portal_after_auth(): string
{
    $to = $_SESSION['post_login_redirect'] ?? 'dashboard.php';
    unset($_SESSION['post_login_redirect']);
    return in_array($to, ['checkout.php', 'cart.php', 'dashboard.php', 'domains.php'], true) ? $to : 'dashboard.php';
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
