<?php
/**
 * Orbit Cloud — live payment status for the M-Pesa waiting screen.
 *
 *   GET  ?pay=ID                → {status, message, …}
 *   POST action=resend, pay=ID  → sends a fresh STK prompt for the same
 *                                 invoice, returns the new payment id
 *
 * status: pending | completed | failed | underpaid
 *
 * GET asks Kopo Kopo at most once every few seconds per payment (the
 * webhook usually settles it first); in between it answers from the
 * database. Only the client who owns the payment can see or resend it.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';
require_once dirname(__DIR__) . '/admin/includes/providers/Provider.php';
require_once dirname(__DIR__) . '/admin/includes/Automation.php';
require_once __DIR__ . '/includes/stk_wait.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

portal_start();
if (empty($_SESSION['client_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Your session has expired — please sign in again.']);
    exit;
}
$cid = (int) $_SESSION['client_id'];

function ps_out(array $body, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

function ps_payment(int $id, int $cid): ?array
{
    $s = db()->prepare('SELECT * FROM payments WHERE id = ? AND client_id = ?');
    $s->execute([$id, $cid]);
    return $s->fetch() ?: null;
}

function ps_state(array $pay): array
{
    $raw = json_decode($pay['raw'] ?? '', true) ?: [];
    if (($pay['review'] ?? null) === 'short') {
        return ['status' => 'underpaid', 'message' => 'We received your payment, but it was less than the amount due. Our billing team will be in touch about the balance — nothing you paid is lost.'];
    }
    if ($pay['status'] === 'completed') {
        return ['status' => 'completed', 'mpesa_ref' => $raw['mpesa_ref'] ?? null,
                'message' => 'Payment received' . (!empty($raw['mpesa_ref']) ? ' — M-Pesa code ' . $raw['mpesa_ref'] : '') . '. Thank you!'];
    }
    if (in_array($pay['status'], ['failed', 'refunded'], true)) {
        return ['status' => 'failed', 'message' => $raw['failure'] ?? 'This payment was not completed.'];
    }
    return ['status' => 'pending', 'message' => 'Waiting for you to approve the prompt on your phone…',
            'age' => stk_payment_age((int) $pay['id'])];
}

$pid = (int) ($_GET['pay'] ?? $_POST['pay'] ?? 0);
$pay = $pid ? ps_payment($pid, $cid) : null;
if (!$pay) ps_out(['status' => 'error', 'message' => 'Payment not found.'], 404);

// ── Resend the prompt ─────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "resend") {
    portal_csrf_verify();
    require_once __DIR__ . "/includes/stk_wait.php";
    $r = stk_resend_payment($pay, (string) ($_POST["phone"] ?? ""));
    if ($r["status"] === "completed") ps_out($r + ps_state($pay));
    ps_out($r, $r["status"] === "error" ? (str_contains($r["message"], "wait") ? 429 : 400) : 200);
}

// ── Status ────────────────────────────────────────────────────
if ($pay['status'] === 'pending') {
    $key  = 'stk_checked_' . $pid;
    $last = (int) ($_SESSION[$key] ?? 0);
    if (time() - $last >= 4) {
        $_SESSION[$key] = time();
        session_write_close(); // don't hold the session lock during the gateway call
        try { Automation::settlePayment($pid); } catch (\Throwable $e) { /* stays pending; next poll retries */ }
        $pay = ps_payment($pid, $cid) ?: $pay;
    }
}
ps_out(['pay' => $pid] + ps_state($pay));
