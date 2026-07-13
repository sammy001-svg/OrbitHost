<?php
/**
 * OrbitHost — one-click cPanel login (SSO)
 * Verifies the logged-in client owns the cPanel account, asks WHM for a
 * temporary session URL (create_user_session) and redirects — the client
 * never types a cPanel username or password.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/WHMClient.php';

portal_check();
$cid  = (int) current_client()['id'];
$user = trim($_GET['user'] ?? '');

function sso_fail(string $msg): void
{
    portal_flash_set('error', $msg);
    header('Location: ' . PORTAL_URL . '/services.php');
    exit;
}

if ($user === '' || !preg_match('/^[a-z0-9_]{1,16}$/i', $user)) {
    sso_fail('Invalid cPanel account.');
}

// ── Ownership check: legacy whm_accounts OR client_services ──
$owned = false;
try {
    $stmt = db()->prepare('SELECT id FROM whm_accounts WHERE cpanel_user = ? AND client_id = ?');
    $stmt->execute([$user, $cid]);
    $owned = (bool) $stmt->fetchColumn();
} catch (\Throwable $e) { /* table may not exist */ }
if (!$owned) {
    try {
        $stmt = db()->prepare('SELECT id FROM client_services WHERE provider_key = "whm" AND client_id = ? AND (username = ? OR remote_id = ?) AND status IN ("active","suspended")');
        $stmt->execute([$cid, $user, $user]);
        $owned = (bool) $stmt->fetchColumn();
    } catch (\Throwable $e) { /* table may not exist */ }
}
if (!$owned) {
    sso_fail('That hosting account is not linked to your profile. Contact support if you believe this is an error.');
}

// ── Ask WHM for a session URL and redirect ──
$cfg = db()->query("SELECT settings FROM integration_settings WHERE provider = 'whm'")->fetchColumn();
$cfg = $cfg ? json_decode($cfg, true) : [];
if (empty($cfg['host']) || empty($cfg['token'])) {
    sso_fail('cPanel login is temporarily unavailable. Please contact support.');
}

try {
    $whm = new WHMClient($cfg['host'], $cfg['user'] ?? 'root', $cfg['token'], (bool)($cfg['ssl_verify'] ?? false));
    $resp = $whm->createUserSession($user);
    // v1 envelope may arrive unwrapped ({url}) or wrapped ({data:{url},metadata})
    $url  = $resp['url'] ?? $resp['data']['url'] ?? '';
    if (!$url) {
        $reason = $resp['metadata']['reason'] ?? $resp['reason'] ?? 'The server did not return a login URL.';
        if (stripos((string)$reason, 'access') !== false || stripos((string)$reason, 'permission') !== false || stripos((string)$reason, 'token') !== false) {
            $reason .= ' (The WHM API token may be missing the "create-user-session" privilege — ask support to update it.)';
        }
        throw new RuntimeException($reason);
    }
    header('Location: ' . $url);
    exit;
} catch (\Throwable $e) {
    sso_fail('Could not open cPanel automatically: ' . $e->getMessage());
}
