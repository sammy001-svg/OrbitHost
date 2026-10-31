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
    /**
     * A Kopo Kopo access token, reused until shortly before it expires.
     *
     * Tokens live an hour. Fetching a fresh one for every call doubled the
     * round trips on each STK push and status check — and the waiting
     * screen checks every few seconds — so it's cached in the database,
     * keyed by environment + client id, so changing keys or flipping
     * sandbox mode can never serve a token for the wrong account.
     */
    private function kopokopoToken(bool $fresh = false): string
    {
        $id   = trim((string) ($this->cfg['client_id'] ?? ''));
        $slot = 'kopokopo:' . (!empty($this->cfg['sandbox']) ? 'sandbox' : 'live') . ':' . substr(hash('sha256', $id), 0, 16);

        if (!$fresh && function_exists('db')) {
            try {
                $s = db()->prepare('SELECT token FROM gateway_tokens WHERE slot = ? AND expires_at > NOW()');
                $s->execute([$slot]);
                $t = $s->fetchColumn();
                if ($t) return (string) $t;
            } catch (\Throwable $e) {
                try {
                    db()->exec('CREATE TABLE IF NOT EXISTS gateway_tokens (
                        slot       VARCHAR(80) NOT NULL PRIMARY KEY,
                        token      TEXT NOT NULL,
                        expires_at DATETIME NOT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                } catch (\Throwable $e2) { /* no CREATE privilege — just fetch every time */ }
            }
        }

        [$token, $ttl] = $this->kopokopoFetchToken();
        if (function_exists('db')) {
            try {
                // Expire our copy two minutes early so an in-flight call never
                // carries a token that dies mid-request.
                db()->prepare('REPLACE INTO gateway_tokens (slot, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))')
                    ->execute([$slot, $token, max(60, $ttl - 120)]);
            } catch (\Throwable $e) {}
        }
        return $token;
    }

    /**
     * An authenticated Kopo Kopo call. A 401 means the cached token was
     * revoked or the keys changed — fetch a new one and retry once.
     */
    private function kopokopoApi(string $url, string $method, $body = null): array
    {
        $call = function (string $token) use ($url, $method, $body) {
            $h = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
            if ($body !== null) $h[] = 'Content-Type: application/json';
            return $this->http($url, $method, $h, $body);
        };
        $r = $call($this->kopokopoToken());
        if ((int) $r['code'] === 401) {
            $r = $call($this->kopokopoToken(true));
        }
        return $r;
    }

    /** @return array{0:string,1:int} [access token, lifetime in seconds] */
    private function kopokopoFetchToken(): array
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
                return [(string) $r['data']['access_token'], (int) ($r['data']['expires_in'] ?? 3600)];
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

    /**
     * Credentials, plus the two settings that make STK pushes silently fail
     * later: the till number, and a callback URL Kopo Kopo can't reach.
     */
    private function kopokopoTestConnection(): array
    {
        $this->kopokopoToken(true);
        $notes = [];
        $till = trim((string) ($this->cfg['till_number'] ?? ''));
        if (!preg_match('/^K?\d{4,8}$/i', $till)) {
            $notes[] = 'Till number "' . $till . '" doesn\'t look like a Kopo Kopo online-payments till (e.g. K123456)';
        }
        if (defined('APP_URL')) {
            $host = (string) parse_url(APP_URL, PHP_URL_HOST);
            if (!str_starts_with(strtolower(APP_URL), 'https://')
                || in_array($host, ['localhost', '127.0.0.1'], true) || preg_match('/^(10|192\.168|172\.(1[6-9]|2\d|3[01]))\./', $host)) {
                $notes[] = 'this site (' . $host . ') isn\'t public HTTPS, so Kopo Kopo can\'t deliver payment callbacks — payments will still confirm, but only while the client watches the payment screen or when the payments cron runs';
            }
        }
        return ['success' => true, 'message' => 'Kopo Kopo credentials valid (' . (!empty($this->cfg['sandbox']) ? 'SANDBOX' : 'LIVE') . ').'
            . ($notes ? ' Warning: ' . implode('; ', $notes) . '.' : ' Till and callback URL look right.')];
    }

    /**
     * A Safaricom M-Pesa number in Kopo Kopo's +2547XXXXXXXX / +2541XXXXXXXX
     * form, or null. Accepts 07…, 01…, 7…, 1…, 254…, +254… and spaces/dashes.
     */
    public static function mpesaPhone(?string $raw): ?string
    {
        $n = preg_replace('/\D+/', '', (string) $raw);
        if (strlen($n) === 9 && ($n[0] === '7' || $n[0] === '1')) $n = '254' . $n;
        elseif (strlen($n) === 10 && $n[0] === '0')               $n = '254' . substr($n, 1);
        return preg_match('/^254[71]\d{8}$/', $n) ? '+' . $n : null;
    }

    /** "+254712345678" → "0712 ••• 678", for showing back to the payer. */
    public static function maskPhone(string $e164): string
    {
        $n = preg_replace('/\D+/', '', $e164);
        $local = '0' . substr($n, 3);
        return strlen($local) === 10 ? substr($local, 0, 4) . ' ••• ' . substr($local, -3) : $e164;
    }

    private function kopokopoCreateCheckout(float $amount, string $currency, string $ref, array $c, array $urls): array
    {
        $phone = self::mpesaPhone($c['phone'] ?? '');
        if (!$phone) {
            return ['success' => false, 'message' => trim((string) ($c['phone'] ?? '')) === ''
                ? 'Enter the M-Pesa phone number to send the payment prompt to.'
                : 'That doesn\'t look like a Safaricom M-Pesa number. Use the format 0712 345 678.'];
        }
        // M-Pesa moves whole shillings only; settlePayment() records what
        // actually arrived, so rounding up never shorts an invoice.
        $value = (int) ceil(round($amount, 2));
        if ($value < 1) return ['success' => false, 'message' => 'Nothing to charge.'];
        if ($value > 250000) return ['success' => false, 'message' => 'M-Pesa caps a single payment at KES 250,000 — please pay this amount by bank transfer.'];

        $name  = trim(preg_replace('/\s+/', ' ', (string) ($c['name'] ?? '')));
        $first = $name !== '' ? explode(' ', $name)[0] : 'Customer';
        $last  = trim(substr($name, strlen($first))) ?: $first;

        $r = $this->kopokopoApi($this->kopokopoBase() . '/api/v1/incoming_payments', 'POST', [
            'payment_channel' => 'M-PESA STK Push',
            'till_number'     => trim((string) ($this->cfg['till_number'] ?? '')),
            'subscriber'      => array_filter(['first_name' => $first, 'last_name' => $last, 'phone_number' => $phone, 'email' => $c['email'] ?? '']),
            'amount'          => ['currency' => 'KES', 'value' => $value],
            'metadata'        => ['reference' => $ref, 'notes' => 'Orbit Cloud ' . $ref],
            '_links'          => ['callback_url' => $urls['callback'] ?? ($urls['return'] ?? '')],
        ]);

        $resource = $r['headers']['location'] ?? '';
        if ((int) $r['code'] !== 201 || $resource === '') {
            $msg = $r['data']['error_message'] ?? ($r['data']['error_description'] ?? null);
            if (!$msg && !empty($r['data']['errors'])) $msg = is_string($r['data']['errors']) ? $r['data']['errors'] : json_encode($r['data']['errors']);
            return ['success' => false, 'message' => 'M-Pesa couldn\'t send the prompt: ' . ($msg ?: 'Kopo Kopo returned HTTP ' . $r['code']) . '. Please try again.'];
        }
        return ['success' => true, 'mode' => 'push', 'ref' => $resource,
                'phone'   => $phone, 'charged' => $value,
                'message' => 'We\'ve sent an M-Pesa prompt for KES ' . number_format($value) . ' to ' . self::maskPhone($phone)
                           . '. Enter your M-Pesa PIN on your phone to approve it.'];
    }

    /**
     * Kopo Kopo / Safaricom failure text → something a customer can act on.
     * The original is kept in brackets for support.
     */
    private static function kopokopoFailureReason($errors): string
    {
        $raw = is_array($errors) ? implode('; ', array_map(fn($e) => is_array($e) ? json_encode($e) : (string) $e, $errors)) : trim((string) $errors);
        $l = strtolower($raw);
        $map = [
            'cancel'        => 'You cancelled the M-Pesa prompt.',
            'insufficient'  => 'Your M-Pesa balance was too low for this payment.',
            'timeout'       => 'The prompt timed out before the PIN was entered.',
            'timed out'     => 'The prompt timed out before the PIN was entered.',
            'ds timeout'    => 'Your phone couldn\'t be reached — make sure it\'s on and has signal, then try again.',
            'unreachable'   => 'Your phone couldn\'t be reached — make sure it\'s on and has signal, then try again.',
            'initiator'     => 'The wrong M-Pesa PIN was entered.',
            'pin'           => 'The wrong M-Pesa PIN was entered.',
            'limit'         => 'This payment is above your M-Pesa transaction limit.',
            'locked'        => 'Your M-Pesa account is locked — contact Safaricom, or pay another way.',
            'not registered'=> 'That number isn\'t registered for M-Pesa.',
        ];
        foreach ($map as $needle => $friendly) {
            if (str_contains($l, $needle)) return $friendly . ($raw !== '' ? ' (' . $raw . ')' : '');
        }
        return 'The M-Pesa payment wasn\'t completed' . ($raw !== '' ? ': ' . $raw : '.');
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
        $url   = rtrim($baseUrl, '/') . '/api/webhooks/kopokopo.php';
        $done  = [];
        $failed = [];

        foreach (self::KK_EVENTS as $event) {
            $r = $this->kopokopoApi($this->kopokopoBase() . '/api/v1/webhook_subscriptions', 'POST',
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
        $r = $this->kopokopoApi($this->kopokopoBase() . '/api/v1/webhook_subscriptions', 'GET');
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
        $r = $this->kopokopoApi($ref, 'GET');
        if ((int) $r['code'] === 0 || (int) $r['code'] >= 500 || (int) $r['code'] === 429) {
            // Kopo Kopo hiccup — not an answer about the payment. Stay pending.
            return ['success' => false, 'status' => 'pending', 'amount' => 0,
                    'message' => 'Still waiting for M-Pesa to confirm…'];
        }
        $attr   = $r['data']['data']['attributes'] ?? [];
        $status = (string) ($attr['status'] ?? 'unknown');
        $res    = $attr['event']['resource'] ?? [];

        if (strcasecmp($status, 'Success') === 0) {
            return ['success' => true, 'status' => 'completed',
                    'amount'  => (float) ($res['amount'] ?? 0),
                    'mpesa_ref' => (string) ($res['reference'] ?? ''),
                    'message' => 'Payment received' . (!empty($res['reference']) ? ' — M-Pesa code ' . $res['reference'] : '') . '.'];
        }
        if (strcasecmp($status, 'Failed') === 0) {
            return ['success' => false, 'status' => 'failed', 'amount' => 0,
                    'message' => self::kopokopoFailureReason($attr['event']['errors'] ?? '')];
        }
        return ['success' => false, 'status' => 'pending', 'amount' => 0,
                'message' => 'Waiting for you to approve the prompt on your phone…'];
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
