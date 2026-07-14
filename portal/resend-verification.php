<?php
/**
 * Orbit Cloud — resend the email-verification link.
 * POST-only, and only ever resends to the currently logged-in client's
 * own address — no email input is accepted, so this can't be used to
 * spam arbitrary addresses.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';
require_once dirname(__DIR__) . '/admin/includes/Notifier.php';

portal_check();
ensure_client_verify_columns();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_csrf_verify();
    $c = current_client();

    $stmt = db()->prepare('SELECT first_name, email, email_verified FROM clients WHERE id = ?');
    $stmt->execute([$c['id']]);
    $client = $stmt->fetch();

    if ($client && !$client['email_verified']) {
        $token = bin2hex(random_bytes(32));
        db()->prepare('UPDATE clients SET verify_token = ?, verify_expires = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ?')
            ->execute([$token, $c['id']]);
        Notifier::send('email_verification', $c['id'], [
            'client_name' => $client['first_name'],
            'email'       => $client['email'],
            'verify_link' => PORTAL_URL . '/verify-email.php?token=' . $token,
            'link'        => PORTAL_URL . '/dashboard.php',
        ]);
        portal_flash_set('success', 'Verification email sent to ' . $client['email'] . '.');
    }
}

header('Location: ' . PORTAL_URL . '/dashboard.php');
exit;
