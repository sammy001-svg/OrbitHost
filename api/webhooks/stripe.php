<?php
/**
 * Orbit Cloud — Stripe webhook receiver.
 *
 * Configure this URL once in the Stripe Dashboard (Developers > Webhooks)
 * for the "checkout.session.completed" (and, for delayed payment methods,
 * "checkout.session.async_payment_succeeded") events, pointed at:
 *   https://yourdomain.com/api/webhooks/stripe.php
 * Save the signing secret it gives you into Providers > Stripe >
 * "Webhook Signing Secret" — every request here is rejected unless its
 * signature verifies against that secret, so this can't be used to
 * fraudulently mark a payment paid; it can only tell us to go re-check
 * with Stripe, same as the reconciliation cron does.
 */
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../admin/includes/config.php';
require_once __DIR__ . '/../../admin/includes/db.php';
require_once __DIR__ . '/../../admin/includes/providers/Provider.php';
require_once __DIR__ . '/../../admin/includes/Automation.php';

function respond(int $code, array $body = []): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

$body      = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret    = (string) (Provider::config('stripe')['webhook_secret'] ?? '');

if ($secret === '') {
    // No signing secret configured — refuse rather than trust an
    // unverifiable request. Set one in Providers > Stripe to enable this.
    respond(400, ['error' => 'Webhook signing secret not configured.']);
}

// Stripe-Signature: "t=1614556800,v1=abcdef...,v1=ghijkl..." (multiple v1
// values appear during secret rotation — any match is acceptable).
$parts = [];
foreach (explode(',', $sigHeader) as $piece) {
    [$k, $v] = array_pad(explode('=', $piece, 2), 2, '');
    $parts[$k][] = $v;
}
$timestamp = $parts['t'][0] ?? '';
$sigs      = $parts['v1'] ?? [];

if ($timestamp === '' || !$sigs || abs(time() - (int) $timestamp) > 300) {
    respond(400, ['error' => 'Invalid or stale signature timestamp.']);
}

$expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
$verified = false;
foreach ($sigs as $sig) {
    if (hash_equals($expected, $sig)) { $verified = true; break; }
}
if (!$verified) {
    respond(400, ['error' => 'Signature verification failed.']);
}

$event = json_decode($body, true);
$type  = $event['type'] ?? '';
$session = $event['data']['object'] ?? [];
$sessionId = $session['id'] ?? '';

if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) && $sessionId !== '') {
    $stmt = db()->prepare("SELECT id FROM payments WHERE gateway = 'stripe' AND gateway_ref = ?");
    $stmt->execute([$sessionId]);
    $payment_id = (int) $stmt->fetchColumn();
    if ($payment_id) {
        try {
            Automation::settlePayment($payment_id);
        } catch (\Throwable $e) { /* reconciliation cron will retry shortly regardless */ }
    }
}

// Any recognized, signature-valid request gets a 200 — including event
// types we don't act on — so Stripe doesn't retry-storm this endpoint.
respond(200, ['ok' => true]);
