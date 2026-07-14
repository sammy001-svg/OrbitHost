<?php
/**
 * Orbit Cloud — shared payment helpers for domain renew / transfer.
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
            'return' => $return_url, 'cancel' => $cancel_url, 'callback' => payment_webhook_url($pay_id),
        ]);
    } catch (\Throwable $e) {
        $r = ['success' => false, 'message' => $e->getMessage()];
    }

    db()->prepare('UPDATE payments SET gateway_ref = ?, status = ?, raw = ? WHERE id = ?')
        ->execute([$r['ref'] ?? null, !empty($r['success']) ? 'pending' : 'failed', json_encode(['context' => $context, 'checkout' => $r]), $pay_id]);

    return ['pay_id' => $pay_id, 'result' => $r];
}
