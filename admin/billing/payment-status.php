<?php
/**
 * Orbit Cloud — live payment status for the admin M-Pesa collect screen.
 * Same contract as portal/payment-status.php, scoped to staff instead of
 * the paying client:
 *
 *   GET  ?pay=ID                → {status, message, …}
 *   POST action=resend, pay=ID  → fresh STK prompt for the same invoice
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/providers/Provider.php';
require_once '../includes/Automation.php';
require_once dirname(__DIR__, 2) . '/portal/includes/stk_wait.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function aps_out(array $body, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

auth_start();
if (empty($_SESSION['admin_id']) || !can('admin')) {
    aps_out(['status' => 'error', 'message' => 'Your admin session has expired — sign in again.'], 401);
}

$pid = (int) ($_GET['pay'] ?? $_POST['pay'] ?? 0);
$s = db()->prepare('SELECT * FROM payments WHERE id = ?');
$s->execute([$pid]);
$pay = $s->fetch();
if (!$pay) aps_out(['status' => 'error', 'message' => 'Payment not found.'], 404);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    csrf_verify();
    $r = stk_resend_payment($pay, (string) ($_POST['phone'] ?? ''));
    if (($r['status'] ?? '') === 'pending') {
        log_activity('stk_resend', 'invoice', (int) $pay['invoice_id'], 'M-Pesa prompt re-sent from admin (payment #' . (int) $r['pay'] . ')');
    }
    aps_out($r, ($r['status'] ?? '') === 'error' ? 400 : 200);
}

if ($pay['status'] === 'pending') {
    $key = 'stk_admin_checked_' . $pid;
    if (time() - (int) ($_SESSION[$key] ?? 0) >= 4) {
        $_SESSION[$key] = time();
        session_write_close();
        try { Automation::settlePayment($pid); } catch (\Throwable $e) {}
        $s->execute([$pid]);
        $pay = $s->fetch() ?: $pay;
    }
}

$raw = json_decode($pay['raw'] ?? '', true) ?: [];
if (($pay['review'] ?? null) === 'short') {
    aps_out(['pay' => $pid, 'status' => 'underpaid', 'message' => 'Payment received but SHORT of the amount due — it is waiting in the short-payment review queue.']);
}
if ($pay['status'] === 'completed') {
    aps_out(['pay' => $pid, 'status' => 'completed', 'mpesa_ref' => $raw['mpesa_ref'] ?? null,
             'message' => 'Payment received' . (!empty($raw['mpesa_ref']) ? ' — M-Pesa code ' . $raw['mpesa_ref'] : '') . '. The invoice is marked paid.']);
}
if (in_array($pay['status'], ['failed', 'refunded'], true)) {
    aps_out(['pay' => $pid, 'status' => 'failed', 'message' => $raw['failure'] ?? 'The payment was not completed.']);
}
aps_out(['pay' => $pid, 'status' => 'pending', 'message' => 'Waiting for the client to approve…',
         'age' => max(0, time() - strtotime((string) $pay['created_at']))]);
