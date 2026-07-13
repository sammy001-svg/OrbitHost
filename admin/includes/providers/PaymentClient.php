<?php
/**
 * OrbitHost — Unified Payment Gateway client
 *
 * One interface over Stripe, PayPal, M-Pesa (Daraja) and Flutterwave so
 * invoices can be paid online regardless of gateway.
 *
 *   createCheckout(amount, currency, reference, customer, urls) →
 *       ['success'=>bool, 'mode'=>'redirect'|'push', 'redirect_url'=>?,
 *        'ref'=>string, 'message'=>string]
 *
 *   verify(ref) → ['success'=>bool, 'status'=>string, 'amount'=>float]
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

    private function dispatch(string $method, array $args): array
    {
        $impl = $this->provider . ucfirst($method);
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
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : ($json ? json_encode($body) : http_build_query($body)));
        }
        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err) throw new RuntimeException('Connection error: ' . $err);
        return ['code' => $code, 'body' => $res, 'data' => json_decode($res, true) ?? []];
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
    // M-PESA — Daraja STK Push
    // ══════════════════════════════════════════════════════════
    private function mpesaBase(): string
    {
        return ($this->cfg['sandbox'] ?? true) ? 'https://sandbox.safaricom.co.ke' : 'https://api.safaricom.co.ke';
    }
    private function mpesaToken(): string
    {
        $auth = base64_encode(($this->cfg['consumer_key'] ?? '') . ':' . ($this->cfg['consumer_secret'] ?? ''));
        $r = $this->http($this->mpesaBase() . '/oauth/v1/generate?grant_type=client_credentials', 'GET',
            ['Authorization: Basic ' . $auth]);
        if (empty($r['data']['access_token'])) throw new RuntimeException('M-Pesa auth failed. Check consumer key/secret.');
        return $r['data']['access_token'];
    }
    private function mpesaTestConnection(): array
    {
        $this->mpesaToken();
        return ['success' => true, 'message' => 'M-Pesa Daraja credentials valid'];
    }
    private function mpesaCreateCheckout(float $amount, string $currency, string $ref, array $c, array $urls): array
    {
        $phone = preg_replace('/\D/', '', $c['phone'] ?? '');
        if (!$phone) return ['success' => false, 'message' => 'A phone number is required for M-Pesa STK Push.'];
        // Normalise to 2547XXXXXXXX
        if (str_starts_with($phone, '0'))      $phone = '254' . substr($phone, 1);
        elseif (str_starts_with($phone, '7'))  $phone = '254' . $phone;

        $token     = $this->mpesaToken();
        $timestamp = date('YmdHis');
        $shortcode = $this->cfg['shortcode'] ?? '';
        $password  = base64_encode($shortcode . ($this->cfg['passkey'] ?? '') . $timestamp);

        $r = $this->http($this->mpesaBase() . '/mpesa/stkpush/v1/processrequest', 'POST',
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            [
                'BusinessShortCode' => $shortcode,
                'Password'          => $password,
                'Timestamp'         => $timestamp,
                'TransactionType'   => 'CustomerPayBillOnline',
                'Amount'            => (int) ceil($amount),
                'PartyA'            => $phone,
                'PartyB'            => $shortcode,
                'PhoneNumber'       => $phone,
                'CallBackURL'       => $urls['callback'] ?? ($urls['return'] ?? ''),
                'AccountReference'  => substr($ref, 0, 12),
                'TransactionDesc'   => 'Payment ' . $ref,
            ]);
        if (($r['data']['ResponseCode'] ?? '1') !== '0') {
            return ['success' => false, 'message' => $r['data']['errorMessage'] ?? ($r['data']['ResponseDescription'] ?? 'STK push failed')];
        }
        return ['success' => true, 'mode' => 'push', 'ref' => $r['data']['CheckoutRequestID'] ?? '',
                'message' => 'STK push sent to ' . $phone . '. Ask the customer to enter their M-Pesa PIN.'];
    }
    private function mpesaVerify(string $ref): array
    {
        $token     = $this->mpesaToken();
        $timestamp = date('YmdHis');
        $shortcode = $this->cfg['shortcode'] ?? '';
        $password  = base64_encode($shortcode . ($this->cfg['passkey'] ?? '') . $timestamp);
        $r = $this->http($this->mpesaBase() . '/mpesa/stkpushquery/v1/query', 'POST',
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            ['BusinessShortCode' => $shortcode, 'Password' => $password, 'Timestamp' => $timestamp, 'CheckoutRequestID' => $ref]);

        // Safaricom omits ResultCode entirely (returning a top-level
        // errorCode/errorMessage instead) while the STK push is still
        // awaiting the customer's PIN — that is NOT a failure. Only a
        // present ResultCode is a final, definitive result: "0" = paid,
        // anything else = genuinely failed (cancelled, wrong PIN, timed
        // out, insufficient funds, etc).
        if (!array_key_exists('ResultCode', $r['data'] ?? [])) {
            return ['success' => false, 'status' => 'pending', 'amount' => 0,
                    'message' => $r['data']['errorMessage'] ?? 'Payment not confirmed yet — waiting for the customer to complete it on their phone.'];
        }
        $paid = (string) $r['data']['ResultCode'] === '0';
        return [
            'success' => $paid,
            'status'  => $paid ? 'completed' : 'failed',
            'amount'  => 0,
            'message' => $r['data']['ResultDesc'] ?? ($paid ? 'Payment completed.' : 'Payment was not completed.'),
        ];
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
