<?php
/**
 * Orbit Cloud — ends a "login as client" session started from
 * admin/clients/impersonate.php. Only ever destroys the portal-side
 * session (orbit_portal) — the admin's own orbit_admin session was
 * never touched when impersonation began, so it's still sitting in
 * the browser and auth_check() will pick it right back up once we
 * land back in /admin/.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

portal_start();

$admin_id  = (int) ($_SESSION['impersonated_by_admin_id'] ?? 0);
$client_id = (int) ($_SESSION['client_id'] ?? 0);

if ($admin_id) {
    try {
        db()->prepare('INSERT INTO activity_log (admin_id, action, entity_type, entity_id, description, ip_address) VALUES (?,?,?,?,?,?)')
            ->execute([$admin_id, 'impersonate_stop', 'client', $client_id, "Stopped impersonating client #{$client_id}", $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (\Throwable $e) {
        // non-fatal — the audit trail is best-effort here
    }
}

session_unset();
session_destroy();

header('Location: ' . (($admin_id && $client_id) ? APP_URL . '/clients/view.php?id=' . $client_id : PORTAL_URL . '/login.php'));
exit;
