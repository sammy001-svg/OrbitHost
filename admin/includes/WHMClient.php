<?php
/**
 * WHM API v1 Client
 * Docs: https://api.docs.cpanel.net/openapi/whm/operation/
 */
class WHMClient
{
    private string $host;
    private string $user;
    private string $token;
    private bool   $sslVerify;

    public function __construct(string $host, string $user, string $token, bool $sslVerify = false)
    {
        // Normalise host: strip any scheme the user typed (http:// OR https://),
        // remove trailing slash and any accidental :port suffix, then always
        // prefix https:// — WHM port 2087 requires SSL; plain HTTP returns a
        // meta-refresh redirect that cURL cannot follow.
        $host = preg_replace('#^https?://#i', '', $host);     // strip whatever scheme was entered
        $host = rtrim($host, '/');
        $host = preg_replace('#:\d+$#', '', $host);           // strip any port the user appended
        $host = 'https://' . $host;                           // always HTTPS
        $this->host      = $host;
        $this->user      = $user;
        $this->token     = $token;
        $this->sslVerify = $sslVerify;
    }

    // ── Core HTTP call ─────────────────────────────────────────
    private function call(string $func, array $params = [], string $method = 'GET'): array
    {
        // Force API v1 — without this WHM answers in the legacy v0 shape
        // ({acct:[…]} at top level) and all the data unwrapping breaks.
        $url = $this->host . ':2087/json-api/' . $func . '?api.version=1';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,           // follow HTTP→HTTPS redirects
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
            CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: whm ' . $this->user . ':' . $this->token,
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } else {
            // $url already ends in "?api.version=1" — join further params with
            // "&", not "?" (a second "?" would corrupt the query string: it
            // gets absorbed into the value of api.version instead of starting
            // new parameters, so e.g. "user" is never actually received).
            $qs = http_build_query($params);
            curl_setopt($ch, CURLOPT_URL, $qs !== '' ? $url . '&' . $qs : $url);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        if (is_resource($ch)) {
            curl_close($ch);
        }

        if ($curlErr) {
            // Give actionable messages for the two most common SSL failures
            if (str_contains($curlErr, 'no alternative certificate subject name')) {
                throw new RuntimeException(
                    'SSL hostname mismatch: the certificate is not valid for the IP address you entered. ' .
                    'Use the server\'s hostname (e.g. corporate.vip8.noc401.com) instead of its IP address, ' .
                    'or uncheck "Verify SSL Certificate" in the WHM settings.'
                );
            }
            if (str_contains($curlErr, 'SSL certificate problem') || str_contains($curlErr, 'self signed')) {
                throw new RuntimeException(
                    'SSL certificate error: ' . $curlErr . '. ' .
                    'Uncheck "Verify SSL Certificate" in WHM settings (safe for self-signed cPanel certs).'
                );
            }
            throw new RuntimeException("WHM connection failed: $curlErr");
        }
        if ($httpCode === 401) {
            throw new RuntimeException('WHM authentication failed. Check username and API token.');
        }
        if ($httpCode === 0) {
            throw new RuntimeException('WHM did not respond. Check the host address and port 2087 is reachable.');
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                "WHM returned invalid JSON (HTTP {$httpCode}). " .
                "Response: " . substr($response, 0, 300)
            );
        }

        return $data;
    }


    // ── Connectivity test ──────────────────────────────────────
    public function ping(): bool
    {
        try {
            $r = $this->getServerVersion();
            return isset($r['version']);
        } catch (Exception $e) {
            return false;
        }
    }

    // ── Account management ─────────────────────────────────────
    public function createAccount(
        string $username,
        string $domain,
        string $password,
        string $package  = 'default',
        string $email    = '',
        string $contactEmail = ''
    ): array {
        return $this->call('createacct', [
            'username'     => $username,
            'domain'       => $domain,
            'password'     => $password,
            'plan'         => $package,
            'email'        => $email,
            'contactemail' => $contactEmail,
            'featurelist'  => 'default',
        ], 'POST');
    }

    public function listAccounts(string $searchType = '', string $search = ''): array
    {
        $params = [];
        if ($search)     $params['search']     = $search;
        if ($searchType) $params['searchtype']  = $searchType; // domain|owner|user|ip|package
        $resp = $this->call('listaccts', $params);
        // v1 wraps the list as { data: { acct: [ … ] } }; legacy v0 puts acct at top level
        return $resp['data']['acct'] ?? $resp['acct'] ?? [];
    }

    public function getAccountSummary(string $username): array
    {
        return $this->call('accountsummary', ['user' => $username]);
    }

    public function suspendAccount(string $username, string $reason = 'Suspended by OrbitHost admin'): array
    {
        return $this->call('suspendacct', ['user' => $username, 'reason' => $reason], 'POST');
    }

    public function unsuspendAccount(string $username): array
    {
        return $this->call('unsuspendacct', ['user' => $username], 'POST');
    }

    public function removeAccount(string $username, bool $keepDns = false): array
    {
        return $this->call('removeacct', ['username' => $username, 'keepdns' => (int)$keepDns], 'POST');
    }

    public function changePassword(string $username, string $newPassword): array
    {
        return $this->call('passwd', ['user' => $username, 'pass' => $newPassword], 'POST');
    }

    public function changePackage(string $username, string $package): array
    {
        return $this->call('changepackage', ['user' => $username, 'pkg' => $package], 'POST');
    }

    // ── Disk & bandwidth ──────────────────────────────────────
    public function getDiskInfo(string $username): array
    {
        $resp = $this->call('getdiskinfo', ['user' => $username]);
        return $resp['data'] ?? $resp;
    }

    public function getBandwidth(string $username): array
    {
        $resp = $this->call('showbw', ['search' => $username, 'searchtype' => 'user']);
        // showbw returns data.acct[0].totalbytes (bytes)
        $acct = $resp['data']['acct'][0] ?? $resp['acct'][0] ?? [];
        return ['bw_used' => isset($acct['totalbytes']) ? (int) round($acct['totalbytes'] / 1048576) : 0];
    }

    // ── Packages ─────────────────────────────────────────────
    public function listPackages(): array
    {
        $resp = $this->call('listpkgs');
        return $resp['data']['pkg'] ?? $resp['pkg'] ?? [];
    }

    /**
     * Create a hosting package. $opts keys: name (required) plus quota,
     * bwlimit, maxftp, maxsql, maxpop, maxsub, maxpark, maxaddon —
     * numeric values in MB, or the string "unlimited".
     */
    public function addPackage(array $opts): array
    {
        return $this->call('addpkg', $opts, 'POST');
    }

    /** Edit an existing package; same $opts as addPackage, name identifies it. */
    public function editPackage(array $opts): array
    {
        return $this->call('editpkg', $opts, 'POST');
    }

    public function deletePackage(string $name): array
    {
        return $this->call('killpkg', ['pkg' => $name], 'POST');
    }

    // ── DNS & domains ─────────────────────────────────────────
    public function listDomains(string $username): array
    {
        return $this->call('cpanel', ['user' => $username, 'cpanel_jsonapi_version' => 2, 'cpanel_jsonapi_module' => 'DomainInfo', 'cpanel_jsonapi_func' => 'list_domains']);
    }

    /**
     * One-click cPanel login: returns a short-lived session URL the
     * browser can be redirected to (no username/password required).
     */
    public function createUserSession(string $username, string $service = 'cpaneld'): array
    {
        $resp = $this->call('create_user_session', ['user' => $username, 'service' => $service]);
        return $resp['data'] ?? $resp; // { url, session, expires, ... }
    }

    // ── Server info ───────────────────────────────────────────
    public function getServerVersion(): array
    {
        $resp = $this->call('version');
        return $resp['data'] ?? $resp; // { version: "11.…" }
    }

    public function getServerLoad(): array
    {
        $resp = $this->call('loadavg');
        return $resp['data'] ?? $resp; // { one, five, fifteen }
    }

    /**
     * Build a safe cPanel username from a domain name.
     * Max 8 chars, lowercase, alphanumeric only.
     */
    public static function buildUsername(string $domain): string
    {
        $base = preg_replace('/[^a-z0-9]/', '', strtolower(explode('.', $domain)[0]));
        return substr($base ?: 'user', 0, 8) . rand(10, 99);
    }

    /**
     * Generate a random strong password for cPanel accounts.
     */
    public static function generatePassword(int $length = 16): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $pass  = '';
        for ($i = 0; $i < $length; $i++) {
            $pass .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $pass;
    }
}
