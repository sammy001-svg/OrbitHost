<?php
/**
 * Orbit Cloud — Shanfix SMS client.
 *
 * Talks to the Shanfix Technology SMS platform's developer API
 * (/api/v1/sendsms, bulksend, balance, status). The same contract is
 * served by the original bulk-SMS platform and by Shanfix System, which
 * kept the old addresses and field names for existing integrations.
 *
 * Every method returns an array and never throws for an API-level
 * failure. 'retry' => true marks failures worth trying again later
 * (rate limit, network, 5xx); anything else is final.
 */
final class SmsClient
{
    /** Platform limits (characters per message, recipients per bulk call). */
    public const MAX_LENGTH = 918;
    public const BULK_LIMIT = 1000;

    private string $provider;
    private array  $cfg;

    public function __construct(string $provider, array $config)
    {
        $this->provider = $provider;
        $this->cfg      = $config;
    }

    public function testConnection(): array
    {
        $b = $this->balance();
        if (!$b['success']) return $b;
        return ['success' => true, 'message' => sprintf('Connected as %s — %s SMS units available.',
            $b['client_name'] ?: 'your account', number_format((float) $b['units'], 2))];
    }

    /** @return array{success:bool, units?:float, client_name?:string, message?:string} */
    public function balance(): array
    {
        $r = $this->call('balance', [], 'GET');
        if (!$r['success']) return $r;
        return ['success' => true, 'units' => (float) ($r['body']['sms_units'] ?? 0),
                'client_name' => (string) ($r['body']['client_name'] ?? '')];
    }

    /**
     * One message to one number.
     * @return array{success:bool, message_id?:int, units?:float, balance?:float, message?:string, retry?:bool}
     */
    public function send(string $to, string $message): array
    {
        $r = $this->call('sendsms', $this->withSender(['to' => $to, 'message' => $message]));
        if (!$r['success']) return $r;
        return ['success' => true,
                'message_id' => isset($r['body']['message_id']) ? (int) $r['body']['message_id'] : null,
                'units'      => (float) ($r['body']['units_charged'] ?? 0),
                'balance'    => (float) ($r['body']['remaining_units'] ?? 0)];
    }

    /**
     * The same message to up to BULK_LIMIT numbers. The platform answers
     * with counts, not per-recipient ids, plus the numbers it rejected.
     * @return array{success:bool, sent?:int, failed?:int, invalid?:string[], units?:float, message?:string, retry?:bool}
     */
    public function bulk(array $numbers, string $message): array
    {
        $r = $this->call('bulksend', $this->withSender(['to' => array_values($numbers), 'message' => $message]), 'POST', 90);
        if (!$r['success']) return $r;
        $inv = $r['body']['invalid_numbers'] ?? [];
        return ['success' => true,
                'sent'    => (int) ($r['body']['sent'] ?? 0),
                'failed'  => (int) ($r['body']['failed'] ?? 0),
                'invalid' => is_array($inv) ? array_map('strval', $inv) : [],
                'units'   => (float) ($r['body']['units_charged'] ?? 0)];
    }

    /**
     * Delivery state for up to 200 message ids.
     * @return array{success:bool, messages?:array<int,array>, message?:string}
     */
    public function status(array $message_ids): array
    {
        $ids = array_slice(array_values(array_filter(array_map('intval', $message_ids))), 0, 200);
        if (!$ids) return ['success' => true, 'messages' => []];
        $r = $this->call('status', ['message_ids' => implode(',', $ids)], 'GET');
        if (!$r['success']) return $r;
        $out = [];
        foreach ($r['body']['messages'] ?? [] as $m) {
            $out[(int) $m['message_id']] = $m;
        }
        return ['success' => true, 'messages' => $out];
    }

    /**
     * Kenyan numbers to the platform's 2547XXXXXXXX / 2541XXXXXXXX form —
     * the same rules the platform applies, so we can reject bad numbers
     * before spending a request on them.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $n = preg_replace('/\D+/', '', (string) $phone);
        if ($n === '') return null;
        if (strlen($n) === 9 && ($n[0] === '7' || $n[0] === '1')) $n = '254' . $n;
        elseif (strlen($n) === 10 && $n[0] === '0')               $n = '254' . substr($n, 1);
        elseif (strlen($n) === 14 && str_starts_with($n, '00'))    $n = substr($n, 2);
        return (strlen($n) === 12 && str_starts_with($n, '254') && in_array($n[3], ['7', '1'], true)) ? $n : null;
    }

    /**
     * How many SMS segments a message costs: GSM-7 text fits 160 (153 when
     * split), anything outside GSM-7 (emoji, curly quotes…) drops to 70 (67).
     */
    public static function segments(string $message): int
    {
        if ($message === '') return 0;
        $gsm = '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r" . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
        $ext = '^{}\\[~]|€';
        $len = 0; $unicode = false;
        foreach (preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            if (mb_strpos($gsm, $ch) !== false)      $len += 1;
            elseif (mb_strpos($ext, $ch) !== false) $len += 2;
            else { $unicode = true; break; }
        }
        if ($unicode) {
            $len = mb_strlen($message);
            return $len <= 70 ? 1 : (int) ceil($len / 67);
        }
        return $len <= 160 ? 1 : (int) ceil($len / 153);
    }

    // ── HTTP ─────────────────────────────────────────────────────
    private function withSender(array $params): array
    {
        $sender = trim((string) ($this->cfg['sender_id'] ?? ''));
        if ($sender !== '') $params['sender_id'] = $sender;
        return $params;
    }

    /** @return array{success:bool, body?:array, message?:string, retry?:bool, http?:int} */
    private function call(string $endpoint, array $params, string $method = 'POST', int $timeout = 15): array
    {
        $base = rtrim(trim((string) ($this->cfg['base_url'] ?? '')), '/');
        if ($base === '') return ['success' => false, 'message' => 'Shanfix SMS platform URL is not set.'];
        if (!preg_match('#^https?://#i', $base)) $base = 'https://' . $base;
        $url = $base . '/api/v1/' . $endpoint . '.php';

        $headers = [
            'Accept: application/json',
            'X-Client-ID: ' . trim((string) ($this->cfg['client_id'] ?? '')),
            'X-Api-Key: '   . trim((string) ($this->cfg['api_key'] ?? '')),
        ];
        $ch = curl_init();
        if ($method === 'GET') {
            if ($params) $url .= '?' . http_build_query($params);
        } else {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            // Single sends run inside page requests (checkout, payments) — keep
            // a slow platform from stalling them; failures retry from the queue.
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false, // a redirect would drop the POST body and leak the key elsewhere
        ]);
        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['success' => false, 'retry' => true, 'message' => 'Could not reach Shanfix SMS: ' . $err];
        }
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            return ['success' => false, 'retry' => $http >= 500 || $http === 0, 'http' => $http,
                    'message' => 'Unexpected response from Shanfix SMS (HTTP ' . $http . '). Check the platform URL.'];
        }
        if ($http >= 200 && $http < 300 && !empty($body['success'])) {
            return ['success' => true, 'body' => $body, 'http' => $http];
        }
        return ['success' => false, 'http' => $http, 'body' => $body,
                'retry'   => $http === 429 || $http >= 500,
                'message' => (string) ($body['error'] ?? ('Shanfix SMS returned HTTP ' . $http . '.'))];
    }
}
