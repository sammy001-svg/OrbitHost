<?php
/**
 * Orbit Cloud — shared payment helpers for domain renew / transfer.
 * Mirrors the invoice + payment + verify pattern used in checkout.php,
 * factored out so renew and transfer don't duplicate the gateway logic.
 */
require_once dirname(__DIR__, 2) . '/admin/includes/functions.php';
require_once dirname(__DIR__, 2) . '/admin/includes/providers/Provider.php';

/**
 * Active + configured payment gateways, optionally filtered to those that
 * can actually settle in $currency. KopoKopo (M-Pesa) hard-codes 'KES' in
 * its own createCheckout() regardless of what's passed to it — it cannot
 * charge a USD amount, so it's excluded whenever the checkout is in USD
 * to avoid silently billing a KES face-value amount instead.
 */
function dp_active_gateways(string $currency = 'USD'): array
{
    $out = [];
    foreach (ProviderRegistry::byCategory('payment') as $key => $def) {
        if (!Provider::isActive($key) || !Provider::isConfigured($key)) continue;
        if ($key === 'kopokopo' && strtoupper($currency) !== 'KES') continue;
        $out[$key] = $def;
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

/**
 * Create a one-line invoice for a domain/service action and return its
 * id. $subtotal, when greater than $amount, records a coupon discount:
 * the invoice's subtotal/total split accordingly and a second, negative
 * line item shows the deduction. Existing callers that don't pass a
 * discount get the original one-line, no-discount invoice unchanged.
 */
function dp_create_invoice(int $client_id, string $description, float $amount, string $currency = 'USD', float $subtotal = 0.0, string $couponCode = ''): int
{
    require_once dirname(__DIR__, 2) . '/admin/includes/Currency.php';
    require_once dirname(__DIR__, 2) . '/admin/includes/Coupon.php';
    Currency::ensureSchema();
    Coupon::ensureInvoiceColumns();
    $subtotal = $subtotal > $amount ? $subtotal : $amount;
    $discount = round($subtotal - $amount, 2);

    $inv_no = generate_invoice_number();
    db()->prepare("INSERT INTO invoices (invoice_number, client_id, subtotal, tax_rate, tax_amount, total, status, due_date, currency, coupon_code, discount_amount) VALUES (?,?,?,0,0,?, 'sent', CURDATE(), ?, ?, ?)")
        ->execute([$inv_no, $client_id, $subtotal, $amount, $currency, $couponCode ?: null, $discount]);
    $id = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total) VALUES (?,?,?,?,?)')
        ->execute([$id, $description, 1, $subtotal, $subtotal]);
    if ($discount > 0) {
        db()->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total) VALUES (?,?,?,?,?)')
            ->execute([$id, 'Discount' . ($couponCode ? " ({$couponCode})" : ''), 1, -$discount, -$discount]);
    }
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
            'return' => $return_url, 'cancel' => $cancel_url, 'callback' => payment_webhook_url($pay_id),
        ]);
    } catch (\Throwable $e) {
        $r = ['success' => false, 'message' => $e->getMessage()];
    }

    db()->prepare('UPDATE payments SET gateway_ref = ?, status = ?, raw = ? WHERE id = ?')
        ->execute([$r['ref'] ?? null, !empty($r['success']) ? 'pending' : 'failed', json_encode(['context' => $context, 'checkout' => $r]), $pay_id]);

    return ['pay_id' => $pay_id, 'result' => $r];
}
