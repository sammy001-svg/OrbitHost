<?php
/**
 * Orbit Cloud — Unified Payment Gateway client
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

    /**
     * Register the gateway's webhooks so payments that happen WITHOUT us
     * starting them still reach the app — someone paying the till directly
     * from their phone, or a settlement landing in the bank account.
     * @param string $baseUrl site root, e.g. https://orbitcloud.co.ke
     */
    public function subscribeWebhooks(string $baseUrl): array
    {
        return $this->dispatch('subscribeWebhooks', [$baseUrl]);
    }

    /** What the gateway currently has registered. */
    public function listWebhooks(): array
    {
        return $this->dispatch('listWebhooks', []);
    }

    /**
     * Is this webhook body genuinely from the gateway?
     * @param string $raw       exact request body, unparsed
     * @param string $signature value of the provider's signature header
     */
    public function verifyWebhook(string $raw, string $signature): bool
    {
        try {
            return (bool) $this->dispatch('verifyWebhook', [$raw, $signature])['valid'];
        } catch (\Throwable $e) {
            return false;
        }
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
            CURLOPT_CONNECTTIMEOUT => 12,
            // Payment APIs sit behind Cloudflare and friends, which reject a
            // request carrying no User-Agent as a bot — the response is an
            // HTML 403 that never reaches the gateway's own application.
            // Identify ourselves properly.
            CURLOPT_USERAGENT      => 'OrbitCloud/1.0 (+billing integration; PHP ' . PHP_VERSION . '; curl)',
            // Accept whatever encoding the edge prefers and let curl inflate
            // it, rather than being handed compressed bytes we cannot parse.
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => true,
            // Some gateway endpoints redirect (http→https, or a trailing
            // slash). Without this cURL returns the empty redirect body and
            // the caller sees "no token" instead of the real response.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
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
        if ($err) {
            // A stale/missing CA bundle is the usual cause on shared hosting,
            // and "SSL certificate problem" alone sends people hunting for
            // the wrong thing.
            if (stripos($err, 'ssl') !== false || stripos($err, 'certificate') !== false) {
                $err .= ' — this is a TLS trust problem on THIS server, not a credential problem'
                     .  ' (its CA bundle is usually out of date; ask your host to update curl.cainfo).';
            }
            throw new RuntimeException('Connection error: ' . $err);
        }
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
    /**
     * Fetch an OAuth access token.
     *
     * Kopo Kopo has shipped more than one accepted shape for this call over
     * time (form body, JSON body, HTTP Basic), and which one a given
     * account/environment accepts is not something we can know in advance.
     * So we try them in order and only give up when all three fail — then
     * report what the gateway actually said, rather than blaming the
     * credentials for what might be a 404, a proxy block or an HTML error
     * page.
     */
    private function kopokopoToken(): string
    {
        // Trim defensively: these are pasted from a dashboard, and a trailing
        // newline or non-breaking space is invisible in the form field.
        $id     = trim((string) ($this->cfg['client_id'] ?? ''));
        $secret = trim((string) ($this->cfg['client_secret'] ?? ''));

        if ($id === '' || $secret === '') {
            throw new RuntimeException('Kopo Kopo: Client ID and Client Secret are both required — one of them is blank.');
        }

        $url  = $this->kopokopoBase() . '/oauth/token';
        $creds = ['grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $secret];
        $tried = [];

        $attempts = [
            ['form',  ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], $creds, false],
            ['json',  ['Content-Type: application/json', 'Accept: application/json'], $creds, true],
            ['basic', ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json',
                       'Authorization: Basic ' . base64_encode($id . ':' . $secret)],
                      ['grant_type' => 'client_credentials'], false],
        ];

        foreach ($attempts as [$label, $headers, $body, $asJson]) {
            try {
                $r = $this->http($url, 'POST', $headers, $body, $asJson);
            } catch (\Throwable $e) {
                // Network/TLS failure — no point trying the other shapes.
                throw new RuntimeException('Kopo Kopo could not be reached at ' . $url . ' — ' . $e->getMessage()
                    . ' (check outbound HTTPS is allowed from this server).');
            }
            if (!empty($r['data']['access_token'])) {
                return (string) $r['data']['access_token'];
            }
            $tried[] = $label . ' → ' . $this->kopokopoAuthProblem($r);
        }

        // Only mention the sandbox/live split when the gateway actually
        // rejected the credentials. On a 404 or a firewall block it is
        // irrelevant, and sends people to re-check keys that were fine.
        $rejected = false;
        foreach ($tried as $t) { if (str_contains($t, '401')) $rejected = true; }

        throw new RuntimeException('Kopo Kopo auth failed. '
            . ($rejected ? $this->kopokopoAuthHint() . ' ' : '')
            . 'Gateway responses: ' . implode(' | ', $tried));
    }

    /** Turn one failed token response into something a human can act on. */
    private function kopokopoAuthProblem(array $r): string
    {
        $code = (int) ($r['code'] ?? 0);
        $d    = $r['data'] ?? [];
        $msg  = $d['error_description'] ?? $d['error_message'] ?? $d['error'] ?? '';

        if ($code === 0)   return 'no HTTP response';
        if ($code === 401) return 'HTTP 401 ' . ($msg ?: 'invalid client credentials');
        if ($code === 404) return 'HTTP 404 (endpoint not found — wrong environment?)';
        if ($code >= 500)  return 'HTTP ' . $code . ' (Kopo Kopo server error — retry shortly)';

        if ($msg !== '') return 'HTTP ' . $code . ' ' . $msg;

        // Not JSON at all: a login page, a WAF block, a proxy notice. Say
        // WHO answered, so it is clear whether the gateway's edge blocked us
        // or something on our own network did.
        $body = trim((string) ($r['body'] ?? ''));
        if ($body !== '' && $body[0] === '<') {
            $hdrs = $r['headers'] ?? [];
            $who  = [];
            if (!empty($hdrs['cf-ray']))  $who[] = 'Cloudflare (ray ' . $hdrs['cf-ray'] . ')';
            elseif (!empty($hdrs['server'])) $who[] = $hdrs['server'];
            if (preg_match('~<title>\s*([^<]{3,80})~i', $body, $m)) $who[] = 'page: ' . trim($m[1]);
            return 'HTTP ' . $code . ' returned HTML, not JSON — blocked by '
                 . ($who ? implode(', ', $who) : 'an unidentified proxy or firewall');
        }
        return 'HTTP ' . $code . ' ' . ($body === '' ? '(empty response)' : mb_strimwidth($body, 0, 90, '…'));
    }

    /** Which environment we are pointed at, spelled out. */
    private function kopokopoAuthHint(): string
    {
        return !empty($this->cfg['sandbox'])
            ? 'Sandbox mode is ON, so these must be your SANDBOX keys from sandbox.kopokopo.com — live keys will always fail here.'
            : 'Sandbox mode is OFF, so these must be your LIVE keys from app.kopokopo.com — sandbox keys will always fail here.';
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
    /**
     * Events worth subscribing to. Without these, the ONLY payments this
     * app ever learns about are the STK pushes it started itself — a
     * customer who pays the till directly from their M-Pesa menu is
     * invisible, and so is the settlement into your bank account.
     */
    private const KK_EVENTS = [
        'buygoods_transaction_received',
        'b2b_transaction_received',
        'settlement_transfer_completed',
    ];

    private function kopokopoSubscribeWebhooks(string $baseUrl): array
    {
        $token = $this->kopokopoToken();
        $url   = rtrim($baseUrl, '/') . '/api/webhooks/kopokopo.php';
        $done  = [];
        $failed = [];

        foreach (self::KK_EVENTS as $event) {
            $r = $this->http($this->kopokopoBase() . '/api/v1/webhook_subscriptions', 'POST',
                ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
                [
                    'event_type'  => $event,
                    'url'         => $url,
                    'scope'       => 'till',
                    'scope_reference' => $this->cfg['till_number'] ?? '',
                ]);
            // 201 created, or 409/422 when it is already registered — both fine.
            if (in_array($r['code'], [200, 201], true)) {
                $done[] = $event;
            } elseif (in_array($r['code'], [409, 422], true)) {
                $done[] = $event . ' (already registered)';
            } else {
                $msg = $r['data']['error_message'] ?? $r['data']['error_description'] ?? ('HTTP ' . $r['code']);
                $failed[] = $event . ': ' . $msg;
            }
        }

        if ($failed) {
            return ['success' => false, 'message' => 'Registered ' . count($done) . ' of ' . count(self::KK_EVENTS)
                    . '. Failed — ' . implode('; ', $failed), 'registered' => $done];
        }
        return ['success' => true, 'message' => 'Webhooks registered for ' . implode(', ', $done) . '. Callback URL: ' . $url,
                'registered' => $done];
    }

    private function kopokopoListWebhooks(): array
    {
        $token = $this->kopokopoToken();
        $r = $this->http($this->kopokopoBase() . '/api/v1/webhook_subscriptions', 'GET',
            ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        $items = $r['data']['data'] ?? [];
        $out = [];
        foreach ((array) $items as $it) {
            $a = $it['attributes'] ?? $it;
            if (!empty($a['event_type'])) $out[] = ['event' => $a['event_type'], 'url' => $a['url'] ?? ''];
        }
        return ['success' => $r['code'] < 400, 'subscriptions' => $out,
                'message' => $r['code'] < 400 ? (count($out) . ' subscription(s) registered.') : ('HTTP ' . $r['code'])];
    }

    /**
     * Kopo Kopo signs the raw body with the API Key using HMAC-SHA256 and
     * sends it as X-KopoKopo-Signature. Compared with hash_equals so the
     * check is not timing-attackable.
     */
    private function kopokopoVerifyWebhook(string $raw, string $signature): array
    {
        $key = (string) ($this->cfg['api_key'] ?? '');
        if ($key === '' || $signature === '') return ['valid' => false];
        $expected = hash_hmac('sha256', $raw, $key);
        return ['valid' => hash_equals($expected, strtolower(trim($signature)))];
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
                'customizations'=> ['title' => 'Orbit Cloud', 'description' => 'Invoice ' . $ref],
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
