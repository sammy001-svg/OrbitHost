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
        // Normalise host: strip trailing slash and any accidental :2087 suffix,
        // then ensure https:// scheme — without it cURL uses HTTP on port 80
        // which just gets the "refresh to :2087" redirect HTML.
        $host = rtrim($host, '/');
        $host = preg_replace('/:2087$/', '', $host);          // remove port if already present
        if (!preg_match('#^https?://#i', $host)) {
            $host = 'https://' . $host;                       // always use HTTPS for WHM
        }
        $this->host      = $host;
        $this->user      = $user;
        $this->token     = $token;
        $this->sslVerify = $sslVerify;
    }

    // ── Core HTTP call ─────────────────────────────────────────
    private function call(string $func, array $params = [], string $method = 'GET'): array
    {
        $url = $this->host . ':2087/json-api/' . $func;

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
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
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
            $r = $this->call('version');
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
        return $this->call('listaccts', $params);
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
        return $this->call('getdiskinfo', ['user' => $username]);
    }

    public function getBandwidth(string $username): array
    {
        return $this->call('showbw', ['search' => $username, 'searchtype' => 'user']);
    }

    // ── Packages ─────────────────────────────────────────────
    public function listPackages(): array
    {
        return $this->call('listpkgs');
    }

    // ── DNS & domains ─────────────────────────────────────────
    public function listDomains(string $username): array
    {
        return $this->call('cpanel', ['user' => $username, 'cpanel_jsonapi_version' => 2, 'cpanel_jsonapi_module' => 'DomainInfo', 'cpanel_jsonapi_func' => 'list_domains']);
    }

    // ── Server info ───────────────────────────────────────────
    public function getServerVersion(): array
    {
        return $this->call('version');
    }

    public function getServerLoad(): array
    {
        return $this->call('loadavg');
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
