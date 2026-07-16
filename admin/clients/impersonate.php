<?php
/**
 * Orbit Cloud — "login as client" for support/admin debugging.
 * Admin and portal sessions live under different session names
 * (orbit_admin / orbit_portal), so this never touches the admin's own
 * session — it only opens a second, separate session cookie for the
 * portal. Ending impersonation (portal/end-impersonation.php) just
 * destroys that portal session; the admin's own login was never
 * disturbed and is still sitting in the browser the whole time.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once dirname(__DIR__, 2) . '/portal/includes/config.php';

auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/clients/index.php');
    exit;
}

csrf_verify();
$id = (int) ($_POST['id'] ?? 0);
require_role('admin', APP_URL . '/clients/view.php?id=' . $id);

$stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute([$id]);
$client = $stmt->fetch();

if (!$client) {
    flash_set('error', 'Client not found.');
    header('Location: ' . APP_URL . '/clients/index.php');
    exit;
}

$admin = current_admin();
log_activity('impersonate_start', 'client', $id, "Started impersonating {$client['first_name']} {$client['last_name']} ({$client['email']})");

// Close the admin session untouched, then open the separate portal one.
session_write_close();
session_name(PORTAL_SESSION);
session_start();
session_regenerate_id(true);
$_SESSION['client_id']    = (int) $client['id'];
$_SESSION['client_name']  = trim($client['first_name'] . ' ' . $client['last_name']);
$_SESSION['client_email'] = $client['email'];
$_SESSION['last_active']  = time();
$_SESSION['impersonated_by_admin_id']   = (int) $admin['id'];
$_SESSION['impersonated_by_admin_name'] = $admin['name'];

header('Location: ' . PORTAL_URL . '/dashboard.php');
exit;
