<?php
/**
 * Orbit Cloud — Kopo Kopo payment callback (webhook).
 *
 * The callback URL is generated per-payment by payment_webhook_url() in
 * admin/includes/functions.php and embeds the payment id directly
 * (?pay=<id>), so this receiver never has to parse Kopo Kopo's callback
 * body to figure out which payment it's for — and, more importantly,
 * never has to TRUST the body's claimed status either. It just re-asks
 * Kopo Kopo directly via Automation::settlePayment(), the exact same
 * verify()-then-fulfil path the client's own return page and the
 * reconciliation cron use. A forged POST to this URL can only trigger a
 * safe re-check, never a fraudulent "paid" — settlePayment() ignores
 * whatever this payload says and asks the gateway itself.
 *
 * Always responds 200 (even on a bad/missing pay id) so Kopo Kopo
 * doesn't retry-storm a URL it half-remembers; genuine reconciliation
 * happens either right here or, failing that, on the next cron pass.
 */
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../admin/includes/config.php';
require_once __DIR__ . '/../../admin/includes/db.php';
require_once __DIR__ . '/../../admin/includes/Automation.php';

$payment_id = (int) ($_GET['pay'] ?? 0);
$body       = file_get_contents('php://input');

if ($payment_id > 0) {
    // Stash the raw callback for an audit trail, then settle. Both are
    // best-effort — a malformed/duplicate callback must never surface as
    // an error to Kopo Kopo (they'd just retry it).
    try {
        $stmt = db()->prepare('SELECT raw FROM payments WHERE id = ? AND gateway = ?');
        $stmt->execute([$payment_id, 'kopokopo']);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            $raw = json_decode($existing ?: '', true) ?: [];
            $raw['webhook'] = json_decode($body, true) ?? $body;
            db()->prepare('UPDATE payments SET raw = ? WHERE id = ?')->execute([json_encode($raw), $payment_id]);
        }
    } catch (\Throwable $e) { /* audit trail only — never blocks settlement */ }

    try {
        Automation::settlePayment($payment_id);
    } catch (\Throwable $e) { /* reconciliation cron will retry shortly regardless */ }
}

http_response_code(200);
echo json_encode(['ok' => true]);
