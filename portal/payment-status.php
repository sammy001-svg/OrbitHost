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
            'age' => max(0, time() - strtotime((string) $pay['created_at']))];
}

$pid = (int) ($_GET['pay'] ?? $_POST['pay'] ?? 0);
$pay = $pid ? ps_payment($pid, $cid) : null;
if (!$pay) ps_out(['status' => 'error', 'message' => 'Payment not found.'], 404);

// ── Resend the prompt ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    portal_csrf_verify();
    if ($pay['gateway'] !== 'kopokopo') ps_out(['status' => 'error', 'message' => 'Only M-Pesa prompts can be resent.'], 400);
    if ($pay['status'] === 'completed') ps_out(['status' => 'completed', 'pay' => $pid] + ps_state($pay));

    $inv = null;
    if ($pay['invoice_id']) {
        $s = db()->prepare('SELECT id, invoice_number, status FROM invoices WHERE id = ? AND client_id = ?');
        $s->execute([(int) $pay['invoice_id'], $cid]);
        $inv = $s->fetch();
    }
    if (!$inv) ps_out(['status' => 'error', 'message' => 'The invoice for this payment is missing — please start again.'], 400);
    if ($inv['status'] === 'paid') ps_out(['status' => 'completed', 'pay' => $pid, 'message' => 'This invoice is already paid.']);

    // One prompt at a time: Safaricom drops a second push to a phone that
    // still has one open, and a double tap shouldn't send two.
    $last = (int) ($_SESSION['stk_resend'][$pay['invoice_id']] ?? 0);
    if (time() - $last < 20) ps_out(['status' => 'error', 'message' => 'Please wait ' . (20 - (time() - $last)) . ' seconds before requesting another prompt.'], 429);
    $_SESSION['stk_resend'][$pay['invoice_id']] = time();

    $raw   = json_decode($pay['raw'] ?? '', true) ?: [];
    $phone = trim((string) ($_POST['phone'] ?? '')) ?: (string) ($raw['checkout']['phone'] ?? '');
    $s = db()->prepare('SELECT first_name, last_name, email, phone FROM clients WHERE id = ?');
    $s->execute([$cid]);
    $client = $s->fetch() ?: [];
    if ($phone === '') $phone = (string) ($client['phone'] ?? '');

    // Charge what is owed NOW — after a part payment that is the balance.
    $amount = Automation::invoiceOutstanding((int) $inv['id']);
    if ($amount < 0.01) ps_out(['status' => 'error', 'message' => 'Nothing is left to pay on this invoice.'], 400);

    db()->prepare('INSERT INTO payments (invoice_id, client_id, gateway, amount, currency, status, raw) VALUES (?,?,?,?,?,"pending",?)')
        ->execute([(int) $inv['id'], $cid, 'kopokopo', $amount, $pay['currency'] ?: 'KES',
                   json_encode(['context' => $raw['context'] ?? ['action' => 'invoice_payment'], 'resent_from' => $pid])]);
    $new_id = (int) db()->lastInsertId();

    try {
        $r = Provider::payment('kopokopo')->createCheckout($amount, $pay['currency'] ?: 'KES', $inv['invoice_number'],
            ['name' => trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')), 'email' => $client['email'] ?? '', 'phone' => $phone],
            ['callback' => payment_webhook_url($new_id)]);
    } catch (\Throwable $e) {
        $r = ['success' => false, 'message' => $e->getMessage()];
    }

    $nraw = json_decode((string) db()->query('SELECT raw FROM payments WHERE id = ' . $new_id)->fetchColumn(), true) ?: [];
    $nraw['checkout'] = $r;
    if (empty($r['success'])) {
        $nraw['failure'] = $r['message'] ?? 'Could not send the prompt.';
        db()->prepare("UPDATE payments SET status = 'failed', raw = ? WHERE id = ?")->execute([json_encode($nraw), $new_id]);
        ps_out(['status' => 'error', 'message' => $nraw['failure']], 400);
    }
    db()->prepare('UPDATE payments SET gateway_ref = ?, raw = ? WHERE id = ?')->execute([$r['ref'] ?? null, json_encode($nraw), $new_id]);

    ps_out(['status' => 'pending', 'pay' => $new_id, 'phone' => PaymentClient::maskPhone((string) $r['phone']),
            'amount' => 'KES ' . number_format((int) $r['charged']), 'message' => $r['message'], 'age' => 0]);
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
