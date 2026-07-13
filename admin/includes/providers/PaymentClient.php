<?php
/**
 * OrbitHost — Unified Payment Gateway client
 *
 * One interface over Stripe, PayPal, M-Pesa STK (Kopo Kopo), Flutterwave
 * and the offline methods (bank transfer, manual M-Pesa, cheque) so
 * invoices can be paid regardless of method.
 *
 *   createCheckout(amount, currency, reference, customer, urls) →
 *       ['success'=>bool, 'mode'=>'redirect'|'push'|'instructions',
 *        'redirect_url'=>?, 'ref'=>string, 'message'=>string]
 *
 *   verify(ref) → ['success'=>bool, 'status'=>string, 'amount'=>float]
 *
 * 'instructions' mode: nothing was charged — the message contains payment
 * instructions for the client, and verify() stays 'pending' until an admin
 * confirms receipt in Billing.
 */
final class PaymentClient
{
    private string $provider;
    private array  $cfg;

    public function __construct(string $provider, array $config)
    {
        $this->provider = strtolower($provider);
        $this->cfg      = $config;
    }

    public function testConnection(): array
    {
        return $this->dispatch('testConnection', []);
    }

    /**
     * @param array $customer  ['name','email','phone']
     * @param array $urls      ['return','cancel','callback']
     */
    public function createCheckout(float $amount, string $currency, string $reference, array $customer = [], array $urls = []): array
    {
        return $this->dispatch('createCheckout', [$amount, $currency, $reference, $customer, $urls]);
    }

    public function verify(string $ref): array
    {
        return $this->dispatch('verify', [$ref]);
    }

    /** Offline (instruction-based) methods share one implementation. */
    private const OFFLINE = ['bank_transfer', 'mpesa_manual', 'cheque'];

    private function dispatch(string $method, array $args): array
    {
        $impl = (in_array($this->provider, self::OFFLINE, true) ? 'offline' : $this->provider) . ucfirst($method);
        if (!method_exists($this, $impl)) {
            throw new RuntimeException("Gateway '{$this->provider}' does not support {$method}().");
        }
        try {
            return $this->$impl(...$args);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ── Small HTTP helper ─────────────────────────────────────
    private function http(string $url, string $method, array $headers, $body = null, bool $json = true): array
    {
        $resHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$resHeaders) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $resHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : ($json ? json_encode($body) : http_build_query($body)));
        }
        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (is_resource($ch)) {
            curl_close($ch);
        }
        if ($err) throw new RuntimeException('Connection error: ' . $err);
        return ['code' => $code, 'body' => $res, 'data' => json_decode($res, true) ?? [], 'headers' => $resHeaders];
    }

    // ══════════════════════════════════════════════════════════
    // STRIPE — Checkout Sessions
    // ══════════════════════════════════════════════════════════
    private function stripeHeaders(): array
    {
        return ['Authorization: Bearer ' . ($this->cfg['secret_key'] ?? ''), 'Content-Type: application/x-www-form-urlencoded'];
    }
    private function stripeTestConnection(): array
    {
        $r = $this->http('https://api.stripe.com/v1/balance', 'GET', $this->stripeHeaders());
        return ['success' => $r['code'] === 200, 'message' => $r['code'] === 200 ? 'Stripe key valid' : ($r['data']['error']['message'] ?? 'Invalid key')];
    }
    private function stripeCreateCheckout(float $amount, string $currency, string $ref, array $c, array $urls): array
    {
        $params = http_build_query([
            'mode'                       => 'payment',
            'success_url'                => $urls['return'] ?? '',
            'cancel_url'                 => $urls['cancel'] ?? '',
            'client_reference_id'        => $ref,
            'customer_email'             => $c['email'] ?? null,
            'line_items[0][quantity]'    => 1,
            'line_items[0][price_data][currency]'     => strtolower($currency),
            'line_items[0][price_data][unit_amount]'  => (int) round($amount * 100),
            'line_items[0][price_data][product_data][name]' => 'Invoice ' . $ref,
        ]);
        $r = $this->http('https://api.stripe.com/v1/checkout/sessions', 'POST', $this->stripeHeaders(), $params);
        if ($r['code'] !== 200) {
            return ['success' => false, 'message' => $r['data']['error']['message'] ?? 'Stripe error'];
        }
        return ['success' => true, 'mode' => 'redirect', 'redirect_url' => $r['data']['url'], 'ref' => $r['data']['id']];
    }
    private function stripeVerify(string $ref): array
    {
        $r = $this->http('https://api.stripe.com/v1/checkout/sessions/' . urlencode($ref), 'GET', $this->stripeHeaders());
        $paid = ($r['data']['payment_status'] ?? '') === 'paid';
        return ['success' => $paid, 'status' => $r['data']['payment_status'] ?? 'unknown', 'amount' => ($r['data']['amount_total'] ?? 0) / 100];
    }

    // ══════════════════════════════════════════════════════════
    // PAYPAL — Orders v2
    // ══════════════════════════════════════════════════════════
    private function paypalBase(): string
    {
        return ($this->cfg['sandbox'] ?? true) ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }
    private function paypalToken(): string
    {
        $auth = base64_encode(($this->cfg['client_id'] ?? '') . ':' . ($this->cfg['client_secret'] ?? ''));
        $r = $this->http($this->paypalBase() . '/v1/oauth2/token', 'POST',
            ['Authorization: Basic ' . $auth, 'Content-Type: application/x-www-form-urlencoded'],
            'grant_type=client_credentials', false);
        if (empty($r['data']['access_token'])) throw new RuntimeException('PayPal auth failed.');
        return $r['data']['access_token'];
    }
    private function paypalTestConnection(): array
    {
        $this->paypalToken();
        return ['success' => true, 'message' => 'PayPal credentials valid'];
    }
    private function paypalCreateCheckout(float $amount, string $currency, string $ref, array $c, array $urls): array
    {
        $token = $this->paypalToken();
        $r = $this->http($this->paypalBase() . '/v2/checkout/orders', 'POST',
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $ref,
                    'amount' => ['currency_code' => $currency, 'value' => number_format($amount, 2, '.', '')],
                ]],
                'application_context' => ['return_url' => $urls['return'] ?? '', 'cancel_url' => $urls['cancel'] ?? ''],
            ]);
        if (($r['code'] ?? 0) >= 400) return ['success' => false, 'message' => $r['data']['message'] ?? 'PayPal error'];
        $approve = '';
        foreach ($r['data']['links'] ?? [] as $l) { if (($l['rel'] ?? '') === 'approve') $approve = $l['href']; }
        return ['success' => true, 'mode' => 'redirect', 'redirect_url' => $approve, 'ref' => $r['data']['id'] ?? ''];
    }
    private function paypalVerify(string $ref): array
    {
        $token = $this->paypalToken();
        $r = $this->http($this->paypalBase() . '/v2/checkout/orders/' . urlencode($ref), 'GET',
            ['Authorization: Bearer ' . $token]);
        $status = $r['data']['status'] ?? 'unknown';
        return ['success' => $status === 'COMPLETED' || $status === 'APPROVED', 'status' => strtolower($status),
                'amount' => (float)($r['data']['purchase_units'][0]['amount']['value'] ?? 0)];
    }

    // ══════════════════════════════════════════════════════════
    // KOPO KOPO — M-Pesa STK Push (api-docs.kopokopo.com)
    // The 201 response carries the payment-request resource URL in its
    // Location header; that URL is our verify() reference.
    // ══════════════════════════════════════════════════════════
    private function kopokopoBase(): string
    {
        return ($this->cfg['sandbox'] ?? true) ? 'https://sandbox.kopokopo.com' : 'https://api.kopokopo.com';
    }
    private function kopokopoToken(): string
    {
        $r = $this->http($this->kopokopoBase() . '/oauth/token', 'POST',
            ['Content-Type: application/x-www-form-urlencoded'],
            ['grant_type' => 'client_credentials', 'client_id' => $this->cfg['client_id'] ?? '', 'client_secret' => $this->cfg['client_secret'] ?? ''],
            false);
        if (empty($r['data']['access_token'])) {
            throw new RuntimeException('Kopo Kopo auth failed — check Client ID/Secret' . (($this->cfg['sandbox'] ?? true) ? ' (sandbox mode is ON — sandbox and live credentials are separate)' : '') . '.');
        }
        return $r['data']['access_token'];
    }
    private function kopokopoTestConnection(): array
    {
        $this->kopokopoToken();
        return ['success' => true, 'message' => 'Kopo Kopo credentials valid'];
    }
    private function kopokopoCreateCheckout(float $amount, string $currency, string $ref, array $c, array $urls): array
    {
        $phone = preg_replace('/\D/', '', $c['phone'] ?? '');
        if (!$phone) return ['success' => false, 'message' => 'A phone number is required for M-Pesa STK Push.'];
        // Normalise to +2547XXXXXXXX (Kopo Kopo wants E.164)
        if (str_starts_with($phone, '0'))     $phone = '254' . substr($phone, 1);
        elseif (str_starts_with($phone, '7')) $phone = '254' . $phone;
        $phone = '+' . $phone;

        $name  = trim($c['name'] ?? '');
        $first = $name !== '' ? explode(' ', $name)[0] : 'Customer';
        $last  = trim(substr($name, strlen($first))) ?: $first;

        $token = $this->kopokopoToken();
        $r = $this->http($this->kopokopoBase() . '/api/v1/incoming_payments', 'POST',
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
            [
                'payment_channel' => 'M-PESA STK Push',
                'till_number'     => $this->cfg['till_number'] ?? '',
                'subscriber'      => ['first_name' => $first, 'last_name' => $last, 'phone_number' => $phone, 'email' => $c['email'] ?? ''],
                // Kopo Kopo STK charges are KES-only; M-Pesa amounts are whole shillings.
                'amount'          => ['currency' => 'KES', 'value' => (int) ceil($amount)],
                'metadata'        => ['reference' => $ref],
                '_links'          => ['callback_url' => $urls['callback'] ?? ($urls['return'] ?? '')],
            ]);

        $resource = $r['headers']['location'] ?? '';
        if ($r['code'] !== 201 || $resource === '') {
            $msg = $r['data']['error_message'] ?? ($r['data']['error_description'] ?? null);
            if (!$msg && !empty($r['data']['errors'])) $msg = json_encode($r['data']['errors']);
            return ['success' => false, 'message' => 'Kopo Kopo rejected the STK push: ' . ($msg ?: 'HTTP ' . $r['code'])];
        }
        return ['success' => true, 'mode' => 'push', 'ref' => $resource,
                'message' => 'STK push sent to ' . $phone . ' — enter the M-Pesa PIN on the phone to approve, then verify below.'];
    }
    private function kopokopoVerify(string $ref): array
    {
        // ref is the payment-request URL Kopo Kopo gave us; never fetch anything else.
        if (!str_starts_with($ref, $this->kopokopoBase() . '/')) {
            return ['success' => false, 'status' => 'failed', 'amount' => 0, 'message' => 'Invalid Kopo Kopo payment reference.'];
        }
        $token = $this->kopokopoToken();
        $r = $this->http($ref, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        $attr   = $r['data']['data']['attributes'] ?? [];
        $status = $attr['status'] ?? 'unknown';

        if (strcasecmp($status, 'Success') === 0) {
            return ['success' => true, 'status' => 'completed',
                    'amount' => (float) ($attr['event']['resource']['amount'] ?? 0),
                    'message' => 'Payment completed.'];
        }
        if (strcasecmp($status, 'Failed') === 0) {
            return ['success' => false, 'status' => 'failed', 'amount' => 0,
                    'message' => $attr['event']['errors'] ?? 'Payment was not completed (cancelled, timed out or insufficient funds).'];
        }
        return ['success' => false, 'status' => 'pending', 'amount' => 0,
                'message' => 'Payment not confirmed yet — waiting for the customer to complete it on their phone.'];
    }

    // ══════════════════════════════════════════════════════════
    // OFFLINE METHODS — bank transfer / manual M-Pesa / cheque.
    // No API: createCheckout() hands back payment instructions and
    // verify() stays pending until an admin confirms receipt.
    // ══════════════════════════════════════════════════════════
    private function offlineTestConnection(): array
    {
        return ['success' => true, 'message' => 'Offline method — no API to test. Clients will see your payment instructions; confirm each payment manually in Billing.'];
    }
    private function offlineCreateCheckout(float $amount, string $currency, string $ref, array $c, array $urls): array
    {
        $money = $currency . ' ' . number_format($amount, 2);
        $parts = match ($this->provider) {
            'bank_transfer' => array_filter([
                'Pay ' . $money . ' by bank transfer to:',
                'Bank: ' . ($this->cfg['bank_name'] ?? ''),
                'Account name: ' . ($this->cfg['account_name'] ?? ''),
                'Account number: ' . ($this->cfg['account_number'] ?? ''),
                ($this->cfg['branch'] ?? '') !== '' ? 'Branch: ' . $this->cfg['branch'] : null,
                ($this->cfg['swift_code'] ?? '') !== '' ? 'SWIFT/Sort code: ' . $this->cfg['swift_code'] : null,
                'Payment reference: ' . $ref,
            ]),
            'mpesa_manual' => array_filter([
                'Pay ' . $money . ' via M-Pesa:',
                'Paybill / Till: ' . ($this->cfg['paybill'] ?? ''),
                ($this->cfg['account_name'] ?? '') !== '' ? 'Registered name: ' . $this->cfg['account_name'] : null,
                'Account / reference: ' . $ref,
            ]),
            'cheque' => array_filter([
                'Pay ' . $money . ' by cheque:',
                'Payable to: ' . ($this->cfg['payee_name'] ?? ''),
                ($this->cfg['delivery'] ?? '') !== '' ? 'Deliver to: ' . $this->cfg['delivery'] : null,
                'Write reference ' . $ref . ' on the back.',
            ]),
            default => ['Contact us to complete payment of ' . $money . ' (reference ' . $ref . ').'],
        };
        if (($this->cfg['instructions'] ?? '') !== '') $parts[] = trim($this->cfg['instructions']);
        $parts[] = 'Your order will be activated as soon as our team confirms the payment.';

        return ['success' => true, 'mode' => 'instructions',
                'ref'     => 'OFF-' . strtoupper(bin2hex(random_bytes(4))),
                'message' => implode("\n", $parts)];
    }
    private function offlineVerify(string $ref): array
    {
        return ['success' => false, 'status' => 'pending', 'amount' => 0,
                'message' => 'This payment is confirmed manually by our billing team once received — you will be notified when it clears.'];
    }

    // ══════════════════════════════════════════════════════════
    // FLUTTERWAVE — Standard payments
    // ══════════════════════════════════════════════════════════
    private function flutterwaveTestConnection(): array
    {
        $r = $this->http('https://api.flutterwave.com/v3/subaccounts', 'GET',
            ['Authorization: Bearer ' . ($this->cfg['secret_key'] ?? '')]);
        return ['success' => $r['code'] === 200, 'message' => $r['code'] === 200 ? 'Flutterwave key valid' : 'Invalid secret key'];
    }
    private function flutterwaveCreateCheckout(float $amount, string $currency, string $ref, array $c, array $urls): array
    {
        $r = $this->http('https://api.flutterwave.com/v3/payments', 'POST',
            ['Authorization: Bearer ' . ($this->cfg['secret_key'] ?? ''), 'Content-Type: application/json'],
            [
                'tx_ref'        => $ref,
                'amount'        => $amount,
                'currency'      => $currency,
                'redirect_url'  => $urls['return'] ?? '',
                'customer'      => ['email' => $c['email'] ?? '', 'name' => $c['name'] ?? '', 'phonenumber' => $c['phone'] ?? ''],
                'customizations'=> ['title' => 'OrbitHost', 'description' => 'Invoice ' . $ref],
            ]);
        if (($r['data']['status'] ?? '') !== 'success') {
            return ['success' => false, 'message' => $r['data']['message'] ?? 'Flutterwave error'];
        }
        return ['success' => true, 'mode' => 'redirect', 'redirect_url' => $r['data']['data']['link'] ?? '', 'ref' => $ref];
    }
    private function flutterwaveVerify(string $ref): array
    {
        $r = $this->http('https://api.flutterwave.com/v3/transactions/verify_by_reference?tx_ref=' . urlencode($ref), 'GET',
            ['Authorization: Bearer ' . ($this->cfg['secret_key'] ?? '')]);
        $status = $r['data']['data']['status'] ?? 'unknown';
        return ['success' => $status === 'successful', 'status' => $status, 'amount' => (float)($r['data']['data']['amount'] ?? 0)];
    }
}
