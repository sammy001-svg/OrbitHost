<?php
/**
 * OrbitHost — shared payment helpers for domain renew / transfer.
 * Mirrors the invoice + payment + verify pattern used in checkout.php,
 * factored out so renew and transfer don't duplicate the gateway logic.
 */
require_once dirname(__DIR__, 2) . '/admin/includes/functions.php';
require_once dirname(__DIR__, 2) . '/admin/includes/providers/Provider.php';

function dp_active_gateways(): array
{
    $out = [];
    foreach (ProviderRegistry::byCategory('payment') as $key => $def) {
        if (Provider::isActive($key) && Provider::isConfigured($key)) $out[$key] = $def;
    }
    return $out;
}

function dp_iso_country(string $name): string
{
    $map = ['Kenya'=>'KE','Uganda'=>'UG','Tanzania'=>'TZ','Rwanda'=>'RW','Ethiopia'=>'ET','Nigeria'=>'NG','Ghana'=>'GH',
            'South Africa'=>'ZA','Egypt'=>'EG','Morocco'=>'MA','USA'=>'US','United Kingdom'=>'GB','Canada'=>'CA',
            'Australia'=>'AU','Germany'=>'DE','France'=>'FR','India'=>'IN','China'=>'CN','UAE'=>'AE','Saudi Arabia'=>'SA'];
    return $map[$name] ?? 'KE';
}

/** Create a one-line invoice for a domain action and return its id. */
function dp_create_invoice(int $client_id, string $description, float $amount): int
{
    $inv_no = generate_invoice_number();
    db()->prepare("INSERT INTO invoices (invoice_number, client_id, subtotal, tax_rate, tax_amount, total, status, due_date) VALUES (?,?,?,0,0,?, 'sent', CURDATE())")
        ->execute([$inv_no, $client_id, $amount, $amount]);
    $id = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total) VALUES (?,?,?,?,?)')
        ->execute([$id, $description, 1, $amount, $amount]);
    return $id;
}

/**
 * Start a gateway checkout for an invoice. $context is arbitrary data
 * (e.g. domain id, action, years) stashed in payments.raw so the verify
 * step knows what to do once payment is confirmed. Returns the
 * gateway's createCheckout() result plus the new payments.id as 'pay_id'.
 */
function dp_start_payment(int $client_id, int $invoice_id, float $amount, string $currency, string $gateway, array $customer, string $return_url, string $cancel_url, array $context = []): array
{
    db()->prepare('INSERT INTO payments (invoice_id, client_id, gateway, amount, currency, status, raw) VALUES (?,?,?,?,?,"pending",?)')
        ->execute([$invoice_id, $client_id, $gateway, $amount, $currency, json_encode(['context' => $context])]);
    $pay_id = (int) db()->lastInsertId();

    // Redirect gateways send the client back to $return_url as-is, so it
    // must carry the payment id or the page can't verify on return.
    if (!str_contains($return_url, 'pay=')) {
        $return_url .= (str_contains($return_url, '?') ? '&' : '?') . 'pay=' . $pay_id;
    }

    $stmt = db()->prepare('SELECT invoice_number FROM invoices WHERE id = ?');
    $stmt->execute([$invoice_id]);
    $inv_no = (string) $stmt->fetchColumn();

    try {
        $r = Provider::payment($gateway)->createCheckout($amount, $currency, $inv_no, $customer, [
            'return' => $return_url, 'cancel' => $cancel_url, 'callback' => $return_url,
        ]);
    } catch (\Throwable $e) {
        $r = ['success' => false, 'message' => $e->getMessage()];
    }

    db()->prepare('UPDATE payments SET gateway_ref = ?, status = ?, raw = ? WHERE id = ?')
        ->execute([$r['ref'] ?? null, !empty($r['success']) ? 'pending' : 'failed', json_encode(['context' => $context, 'checkout' => $r]), $pay_id]);

    return ['pay_id' => $pay_id, 'result' => $r];
}

/** Pull the context array stashed by dp_start_payment back out of a payments row. */
function dp_context(array $payment): array
{
    $raw = json_decode($payment['raw'] ?? '', true);
    return $raw['context'] ?? [];
}

/**
 * Verify a payment belonging to this client. Returns:
 *   ['ok'=>true,  'payment'=>row, 'already'=>bool]    — confirmed paid
 *   ['ok'=>false, 'failed'=>true, 'payment'=>row, ...] — definitively failed
 *   ['ok'=>false, 'pending'=>true, 'message'=>...]     — not confirmed yet, keep waiting
 *   ['ok'=>false, 'message'=>...]                      — not found / error
 * Marks the payment completed/failed and its invoice paid on success —
 * does NOT perform the domain action itself; the caller does that once
 * and only once (idempotency is the caller's responsibility).
 */
function dp_verify(int $payment_id, int $client_id): array
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE id = ? AND client_id = ?');
    $stmt->execute([$payment_id, $client_id]);
    $pay = $stmt->fetch();
    if (!$pay) return ['ok' => false, 'message' => 'Payment record not found.'];
    if ($pay['status'] === 'completed') return ['ok' => true, 'already' => true, 'payment' => $pay];
    if ($pay['status'] === 'failed') return ['ok' => false, 'failed' => true, 'already' => true, 'payment' => $pay, 'message' => 'This payment attempt failed. Please start a new payment.'];

    try {
        $v = Provider::payment($pay['gateway'])->verify($pay['gateway_ref']);
    } catch (\Throwable $e) {
        return ['ok' => false, 'pending' => true, 'message' => $e->getMessage()];
    }
    if (empty($v['success'])) {
        if (($v['status'] ?? '') === 'failed') {
            db()->prepare("UPDATE payments SET status = 'failed' WHERE id = ?")->execute([$payment_id]);
            return ['ok' => false, 'failed' => true, 'payment' => $pay, 'message' => $v['message'] ?? 'Payment failed.'];
        }
        return ['ok' => false, 'pending' => true, 'message' => $v['message'] ?? ($v['status'] ?? 'Payment not confirmed yet.')];
    }

    db()->prepare("UPDATE payments SET status = 'completed' WHERE id = ?")->execute([$payment_id]);
    if ($pay['invoice_id']) {
        db()->prepare("UPDATE invoices SET status = 'paid', paid_date = CURDATE(), payment_method = ? WHERE id = ?")
            ->execute([$pay['gateway'], $pay['invoice_id']]);
        // Lifecycle hook: provisions a first order / advances renewal dates /
        // reactivates a suspended service tied to this invoice.
        try {
            require_once dirname(__DIR__, 2) . '/admin/includes/Automation.php';
            Automation::invoicePaid((int) $pay['invoice_id']);
        } catch (\Throwable $e) { /* never block payment confirmation */ }
    }
    return ['ok' => true, 'payment' => $pay];
}
