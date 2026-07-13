<?php
/**
 * Multi-provider Domain Registrar Client
 * Supports: Namecheap, GoDaddy, Enom, ResellerClub, Cloudflare, Manual
 */
class DomainClient
{
    private string $provider;
    private array  $config;

    public function __construct(string $provider, array $config)
    {
        $this->provider = strtolower($provider);
        $this->config   = $config;
    }

    // ── Factory: load from integration_settings table ─────────
    public static function fromDB(string $provider): self
    {
        require_once __DIR__ . '/db.php';
        $stmt = db()->prepare("SELECT settings, is_active FROM integration_settings WHERE provider = ?");
        $stmt->execute([$provider]);
        $row = $stmt->fetch();
        if (!$row || !$row['is_active']) {
            throw new RuntimeException("Provider '$provider' is not configured or not active.");
        }
        return new self($provider, json_decode($row['settings'], true));
    }

    // ── Availability check ─────────────────────────────────────
    public function check(string $domain): array
    {
        return match($this->provider) {
            'namecheap'    => $this->ncCheck($domain),
            'godaddy'      => $this->gdCheck($domain),
            'enom'         => $this->enomCheck($domain),
            'resellerclub',
            'netearthone'  => $this->rcCheck($domain),
            'cloudflare'   => ['domain' => $domain, 'available' => null, 'provider' => 'cloudflare', 'note' => 'Cloudflare Registrar has no public availability API — check in the Cloudflare dashboard.'],
            default        => throw new RuntimeException("Unsupported provider: {$this->provider}"),
        };
    }

    /**
     * Check one SLD against many TLDs. LogicBoxes brands do it in a single
     * API call; other providers fall back to per-domain checks.
     * @return array<string,?bool> "sld.tld" => true|false|null(unknown)
     */
    public function checkBulk(string $sld, array $tlds): array
    {
        if (in_array($this->provider, ['resellerclub', 'netearthone'], true)) {
            $data = $this->rcCall('domains/available.json', ['domain-name' => $sld], 'GET', ['tlds' => $tlds]);
            $out  = [];
            foreach ($tlds as $t) {
                $st = $data["$sld.$t"]['status'] ?? 'unknown';
                $out["$sld.$t"] = $st === 'available' ? true : ($st === 'unknown' ? null : false);
            }
            return $out;
        }
        $out = [];
        foreach ($tlds as $t) {
            try {
                $r = $this->check("$sld.$t");
                $out["$sld.$t"] = $r['available'];
            } catch (\Throwable $e) {
                $out["$sld.$t"] = null;
            }
        }
        return $out;
    }

    /**
     * Fetch the provider's TLD list with our wholesale cost (1-year prices).
     * @return array<string,array{register:float,renew:float,transfer:float}> keyed by tld
     */
    public function getTldPricing(): array
    {
        return match($this->provider) {
            'resellerclub',
            'netearthone' => $this->lbTldPricing(),
            default       => throw new RuntimeException('TLD pricing sync is available for NetEarthOne / ResellerClub (LogicBoxes) providers.'),
        };
    }

    /** Verify the configured credentials work. */
    public function testConnection(): array
    {
        try {
            return match($this->provider) {
                'namecheap'    => ['success' => (bool)$this->ncCall('namecheap.domains.getTldList'), 'message' => 'Namecheap credentials valid'],
                'godaddy'      => ['success' => true, 'message' => 'GoDaddy reachable', 'data' => $this->gdCall('/domains/available?domain=example-check-orbit.com')],
                'enom'         => ['success' => str_contains($this->enomRaw('GetBalance'), 'AvailableBalance'), 'message' => 'Enom credentials valid'],
                'resellerclub' => ['success' => true, 'message' => 'ResellerClub reachable', 'data' => $this->rcCall('domains/available.json', ['domain-name' => 'orbit'], 'GET', ['tlds' => ['com']])],
                'netearthone'  => ['success' => true, 'message' => 'NetEarthOne credentials valid', 'data' => $this->rcCall('domains/available.json', ['domain-name' => 'orbit'], 'GET', ['tlds' => ['com']])],
                'cloudflare'   => $this->cfTest(),
                default        => throw new RuntimeException("Unsupported provider: {$this->provider}"),
            };
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // Check multiple TLDs at once
    public function checkMultiple(string $sld, array $tlds = ['com','net','org','co.ke','ke']): array
    {
        $results = [];
        foreach ($tlds as $tld) {
            try {
                $results[] = $this->check("$sld.$tld");
            } catch (Exception $e) {
                $results[] = ['domain' => "$sld.$tld", 'available' => null, 'error' => $e->getMessage()];
            }
        }
        return $results;
    }

    // ── Domain registration ────────────────────────────────────
    public function register(string $domain, array $contact, int $years = 1, array $nameservers = []): array
    {
        return match($this->provider) {
            'namecheap'    => $this->ncRegister($domain, $contact, $years, $nameservers),
            'godaddy'      => $this->gdRegister($domain, $contact, $years),
            'enom'         => $this->enomRegister($domain, $contact, $years, $nameservers),
            'resellerclub',
            'netearthone'  => $this->lbRegister($domain, $contact, $years, $nameservers),
            'cloudflare'   => ['success' => false, 'message' => 'Cloudflare only registers domains already in your account via the dashboard.'],
            default        => throw new RuntimeException("Unsupported provider: {$this->provider}"),
        };
    }

    // ── Domain renewal ────────────────────────────────────────
    public function renew(string $domain, int $years = 1): array
    {
        return match($this->provider) {
            'namecheap'    => $this->ncRenew($domain, $years),
            'godaddy'      => ['success' => false, 'message' => 'Use GoDaddy panel to renew.'],
            'enom'         => $this->enomRenew($domain, $years),
            'resellerclub',
            'netearthone'  => $this->lbRenew($domain, $years),
            'cloudflare'   => ['success' => false, 'message' => 'Cloudflare auto-renews at cost; manage in the dashboard.'],
            default        => throw new RuntimeException("Unsupported provider: {$this->provider}"),
        };
    }

    // ── Domain transfer-in (from another registrar to us) ─────
    public function transfer(string $domain, string $authCode, array $contact, int $years = 1, array $nameservers = []): array
    {
        return match($this->provider) {
            'resellerclub',
            'netearthone'  => $this->lbTransfer($domain, $authCode, $contact, $years, $nameservers),
            'namecheap', 'godaddy', 'enom', 'cloudflare'
                           => ['success' => false, 'message' => 'Automated transfers are not available for ' . ucfirst($this->provider) . ' yet — our team will complete this transfer manually.'],
            default        => throw new RuntimeException("Unsupported provider: {$this->provider}"),
        };
    }

    // ── Nameserver update ─────────────────────────────────────
    public function setNameservers(string $domain, array $nameservers): array
    {
        return match($this->provider) {
            'namecheap'    => $this->ncSetNameservers($domain, $nameservers),
            'godaddy'      => $this->gdSetNameservers($domain, $nameservers),
            'enom'         => $this->enomSetNameservers($domain, $nameservers),
            'resellerclub',
            'netearthone'  => $this->lbSetNameservers($domain, $nameservers),
            'cloudflare'   => ['success' => false, 'message' => 'Cloudflare domains use Cloudflare nameservers by design.'],
            default        => throw new RuntimeException("Unsupported provider: {$this->provider}"),
        };
    }

    // ── Get domain info ───────────────────────────────────────
    public function getInfo(string $domain): array
    {
        return match($this->provider) {
            'namecheap'    => $this->ncGetInfo($domain),
            'godaddy'      => $this->gdGetInfo($domain),
            'enom'         => $this->enomGetInfo($domain),
            'resellerclub',
            'netearthone'  => $this->rcGetInfo($domain),
            'cloudflare'   => $this->cfGetInfo($domain),
            default        => throw new RuntimeException("Unsupported provider: {$this->provider}"),
        };
    }

    // ══════════════════════════════════════════════════════════
    // NAMECHEAP Implementation
    // API docs: https://www.namecheap.com/support/api/methods/
    // ══════════════════════════════════════════════════════════

    private function ncBase(): string
    {
        return $this->config['sandbox']
            ? 'https://api.sandbox.namecheap.com/xml.response'
            : 'https://api.namecheap.com/xml.response';
    }

    private function ncParams(string $command, array $extra = []): array
    {
        return array_merge([
            'ApiUser'  => $this->config['api_user'],
            'ApiKey'   => $this->config['api_key'],
            'UserName' => $this->config['api_user'],
            'ClientIp' => $_SERVER['SERVER_ADDR'] ?? '127.0.0.1',
            'Command'  => $command,
        ], $extra);
    }

    private function ncCall(string $command, array $params = [], string $method = 'GET'): \SimpleXMLElement
    {
        $allParams = $this->ncParams($command, $params);
        $url       = $this->ncBase();

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($allParams));
        } else {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($allParams));
        }

        $response = curl_exec($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) throw new RuntimeException("Namecheap connection error: $err");

        $xml = @simplexml_load_string($response);
        if (!$xml) throw new RuntimeException('Namecheap returned invalid XML.');

        if ((string)$xml['Status'] === 'ERROR') {
            $errMsg = (string)($xml->Errors->Error ?? 'Unknown Namecheap error');
            throw new RuntimeException("Namecheap API error: $errMsg");
        }

        return $xml;
    }

    private function ncCheck(string $domain): array
    {
        $xml  = $this->ncCall('namecheap.domains.check', ['DomainList' => $domain]);
        $res  = $xml->CommandResponse->DomainCheckResult;
        $avail = (string)$res['Available'] === 'true';
        $price = null;

        if ($avail && isset($res->RRPCode)) {
            $price = (float)($res->PremiumRegistrationPrice ?? 0);
        }

        return [
            'domain'    => $domain,
            'available' => $avail,
            'price'     => $price,
            'provider'  => 'namecheap',
        ];
    }

    private function ncRegister(string $domain, array $c, int $years, array $ns = []): array
    {
        [$sld, $tld] = array_pad(explode('.', $domain, 2), 2, 'com');

        $params = [
            'DomainName'                   => $domain,
            'Years'                        => $years,
            'AuxBillingFirstName'          => $c['first_name'] ?? 'Admin',
            'AuxBillingLastName'           => $c['last_name']  ?? 'User',
            'AuxBillingAddress1'           => $c['address']    ?? 'N/A',
            'AuxBillingCity'               => $c['city']       ?? 'Nairobi',
            'AuxBillingCountry'            => $c['country_code'] ?? 'KE',
            'AuxBillingPhone'              => $c['phone']      ?? '+254.700000000',
            'AuxBillingEmailAddress'       => $c['email']      ?? '',
            'AuxBillingPostalCode'         => $c['postcode']   ?? '00100',
            'RegistrantFirstName'          => $c['first_name'] ?? 'Admin',
            'RegistrantLastName'           => $c['last_name']  ?? 'User',
            'RegistrantAddress1'           => $c['address']    ?? 'N/A',
            'RegistrantCity'               => $c['city']       ?? 'Nairobi',
            'RegistrantCountry'            => $c['country_code'] ?? 'KE',
            'RegistrantPhone'              => $c['phone']      ?? '+254.700000000',
            'RegistrantEmailAddress'       => $c['email']      ?? '',
            'RegistrantPostalCode'         => $c['postcode']   ?? '00100',
            'TechFirstName'                => $c['first_name'] ?? 'Admin',
            'TechLastName'                 => $c['last_name']  ?? 'User',
            'TechAddress1'                 => $c['address']    ?? 'N/A',
            'TechCity'                     => $c['city']       ?? 'Nairobi',
            'TechCountry'                  => $c['country_code'] ?? 'KE',
            'TechPhone'                    => $c['phone']      ?? '+254.700000000',
            'TechEmailAddress'             => $c['email']      ?? '',
            'TechPostalCode'               => $c['postcode']   ?? '00100',
            'AdminFirstName'               => $c['first_name'] ?? 'Admin',
            'AdminLastName'                => $c['last_name']  ?? 'User',
            'AdminAddress1'                => $c['address']    ?? 'N/A',
            'AdminCity'                    => $c['city']       ?? 'Nairobi',
            'AdminCountry'                 => $c['country_code'] ?? 'KE',
            'AdminPhone'                   => $c['phone']      ?? '+254.700000000',
            'AdminEmailAddress'            => $c['email']      ?? '',
            'AdminPostalCode'              => $c['postcode']   ?? '00100',
        ];

        if ($ns) {
            $params['Nameservers'] = implode(',', array_slice($ns, 0, 5));
        }

        $xml    = $this->ncCall('namecheap.domains.create', $params, 'POST');
        $result = $xml->CommandResponse->DomainCreateResult;

        return [
            'success'     => (string)$result['Registered'] === 'true',
            'domain'      => $domain,
            'transaction' => (string)$result['OrderID'],
            'expires'     => date('Y-m-d', strtotime('+' . $years . ' years')),
        ];
    }

    private function ncRenew(string $domain, int $years): array
    {
        [$sld, $tld] = explode('.', $domain, 2) + [1 => 'com'];
        $xml    = $this->ncCall('namecheap.domains.renew', ['DomainName' => $domain, 'Years' => $years], 'POST');
        $result = $xml->CommandResponse->DomainRenewResult;
        return [
            'success' => (string)$result['Renewed'] === 'true',
            'domain'  => $domain,
            'expires' => (string)$result['ExpireDate'],
        ];
    }

    private function ncSetNameservers(string $domain, array $ns): array
    {
        [$sld, $tld] = explode('.', $domain, 2) + [1 => 'com'];
        $this->ncCall('namecheap.domains.dns.setCustom', [
            'SLD'         => $sld,
            'TLD'         => $tld,
            'Nameservers' => implode(',', $ns),
        ], 'POST');
        return ['success' => true];
    }

    private function ncGetInfo(string $domain): array
    {
        $xml = $this->ncCall('namecheap.domains.getInfo', ['DomainName' => $domain]);
        $d   = $xml->CommandResponse->DomainGetInfoResult;
        return [
            'domain'     => $domain,
            'status'     => (string)$d['Status'],
            'created'    => (string)($d->DomainDetails->CreatedDate ?? ''),
            'expires'    => (string)($d->DomainDetails->ExpiredDate ?? ''),
            'auto_renew' => (string)$d->DomainDetails->AutoRenew === 'true',
            'locked'     => (string)$d->DomainDetails->IsLocked === 'true',
        ];
    }

    // ══════════════════════════════════════════════════════════
    // GODADDY Implementation
    // API docs: https://developer.godaddy.com/doc/endpoint/domains
    // ══════════════════════════════════════════════════════════

    private function gdBase(): string
    {
        return $this->config['sandbox']
            ? 'https://api.ote-godaddy.com/v1'
            : 'https://api.godaddy.com/v1';
    }

    private function gdCall(string $path, string $method = 'GET', array $body = []): array
    {
        $url = $this->gdBase() . $path;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: sso-key ' . $this->config['api_key'] . ':' . $this->config['api_secret'],
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        if ($body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $response = curl_exec($ch);
        $err      = curl_error($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) throw new RuntimeException("GoDaddy connection error: $err");

        $data = json_decode($response, true) ?? [];
        if (isset($data['code']) && $code >= 400) {
            throw new RuntimeException('GoDaddy error: ' . ($data['message'] ?? $data['code']));
        }
        return $data;
    }

    private function gdCheck(string $domain): array
    {
        $data = $this->gdCall('/domains/available?domain=' . urlencode($domain));
        return [
            'domain'    => $domain,
            'available' => $data['available'] ?? false,
            'price'     => isset($data['price']) ? $data['price'] / 1000000 : null,
            'currency'  => $data['currency']  ?? 'USD',
            'provider'  => 'godaddy',
        ];
    }

    private function gdRegister(string $domain, array $c, int $years): array
    {
        $body = [
            'domain'  => $domain,
            'period'  => $years,
            'renewAuto' => true,
            'privacy' => false,
            'registrant' => [
                'firstName'   => $c['first_name'] ?? 'Admin',
                'lastName'    => $c['last_name']  ?? 'User',
                'email'       => $c['email']      ?? '',
                'phone'       => $c['phone']      ?? '+254.700000000',
                'addressMailing' => [
                    'address1'   => $c['address']  ?? 'N/A',
                    'city'       => $c['city']     ?? 'Nairobi',
                    'country'    => $c['country_code'] ?? 'KE',
                    'postalCode' => $c['postcode'] ?? '00100',
                    'state'      => $c['state']    ?? 'NB',
                ],
            ],
        ];
        $body['admin']  = $body['registrant'];
        $body['tech']   = $body['registrant'];
        $body['billing']= $body['registrant'];

        try {
            $data = $this->gdCall('/domains/purchase', 'POST', $body);
            return ['success' => true, 'domain' => $domain, 'order_id' => $data['orderId'] ?? ''];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function gdSetNameservers(string $domain, array $ns): array
    {
        $servers = array_map(fn($n) => ['nameserver' => $n], $ns);
        $this->gdCall('/domains/' . urlencode($domain) . '/nameservers', 'PUT', ['nameServers' => $servers]);
        return ['success' => true];
    }

    private function gdGetInfo(string $domain): array
    {
        $data = $this->gdCall('/domains/' . urlencode($domain));
        return [
            'domain'     => $domain,
            'status'     => strtolower($data['status'] ?? 'unknown'),
            'created'    => $data['createdAt'] ?? '',
            'expires'    => $data['expires']   ?? '',
            'auto_renew' => $data['renewAuto'] ?? false,
            'locked'     => $data['locked']    ?? false,
        ];
    }

    // ══════════════════════════════════════════════════════════
    // ENOM — reseller XML API (interface.asp)
    // ══════════════════════════════════════════════════════════
    private function enomBase(): string
    {
        return ($this->config['sandbox'] ?? true)
            ? 'https://resellertest.enom.com/interface.asp'
            : 'https://reseller.enom.com/interface.asp';
    }
    private function enomRaw(string $command, array $params = []): string
    {
        $q = http_build_query(array_merge([
            'command'      => $command,
            'uid'          => $this->config['uid'] ?? '',
            'pw'           => $this->config['password'] ?? '',
            'responsetype' => 'xml',
        ], $params));
        $ch = curl_init($this->enomBase() . '?' . $q);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) throw new RuntimeException("Enom connection error: $err");
        return $res ?: '';
    }
    private function enomCall(string $command, array $params = []): \SimpleXMLElement
    {
        $xml = @simplexml_load_string($this->enomRaw($command, $params));
        if (!$xml) throw new RuntimeException('Enom returned invalid XML.');
        if ((int)($xml->ErrCount ?? 0) > 0) {
            throw new RuntimeException('Enom error: ' . (string)($xml->errors->Err1 ?? 'unknown'));
        }
        return $xml;
    }
    private function enomCheck(string $domain): array
    {
        [$sld, $tld] = array_pad(explode('.', $domain, 2), 2, 'com');
        $xml = $this->enomCall('check', ['sld' => $sld, 'tld' => $tld]);
        return ['domain' => $domain, 'available' => (int)$xml->RRPCode === 210, 'provider' => 'enom'];
    }
    private function enomRegister(string $domain, array $c, int $years, array $ns = []): array
    {
        [$sld, $tld] = array_pad(explode('.', $domain, 2), 2, 'com');
        $xml = $this->enomCall('purchase', [
            'sld' => $sld, 'tld' => $tld, 'numyears' => $years,
            'registrantfirstname' => $c['first_name'] ?? 'Admin',
            'registrantlastname'  => $c['last_name']  ?? 'User',
            'registrantemailaddress' => $c['email'] ?? '',
            'registrantphone'     => $c['phone'] ?? '+254.700000000',
        ]);
        return ['success' => (string)$xml->RRPCode === '200', 'domain' => $domain, 'transaction' => (string)($xml->OrderID ?? '')];
    }
    private function enomRenew(string $domain, int $years): array
    {
        [$sld, $tld] = array_pad(explode('.', $domain, 2), 2, 'com');
        $xml = $this->enomCall('extend', ['sld' => $sld, 'tld' => $tld, 'numyears' => $years]);
        return ['success' => (string)$xml->RRPCode === '200', 'domain' => $domain];
    }
    private function enomSetNameservers(string $domain, array $ns): array
    {
        [$sld, $tld] = array_pad(explode('.', $domain, 2), 2, 'com');
        $params = ['sld' => $sld, 'tld' => $tld];
        foreach (array_slice(array_values($ns), 0, 12) as $i => $n) { $params['ns' . ($i + 1)] = $n; }
        $this->enomCall('modifyns', $params);
        return ['success' => true];
    }
    private function enomGetInfo(string $domain): array
    {
        [$sld, $tld] = array_pad(explode('.', $domain, 2), 2, 'com');
        $xml = $this->enomCall('GetDomainInfo', ['sld' => $sld, 'tld' => $tld]);
        return [
            'domain'  => $domain,
            'status'  => (string)($xml->GetDomainInfo->status->registrationstatus ?? 'unknown'),
            'expires' => (string)($xml->GetDomainInfo->status->expiration ?? ''),
        ];
    }

    // ══════════════════════════════════════════════════════════
    // LOGICBOXES (ResellerClub, NetEarthOne) — JSON API
    // Both brands run on the same platform; api_base is configurable.
    // ══════════════════════════════════════════════════════════
    private function rcBase(): string
    {
        // Sandbox always uses the shared LogicBoxes demo environment
        if (!empty($this->config['sandbox'])) {
            return 'https://test.httpapi.com/api';
        }
        $base = rtrim(trim($this->config['api_base'] ?? ''), '/');
        if ($base !== '') {
            if (!preg_match('#/api$#i', $base)) $base .= '/api';
            return $base;
        }
        return 'https://httpapi.com/api';
    }

    /**
     * @param array $repeat keys whose values are arrays sent as repeated
     *                      query params (LogicBoxes style: &ns=a&ns=b)
     */
    private function rcCall(string $path, array $params = [], string $method = 'GET', array $repeat = []): array
    {
        $q = http_build_query(array_merge([
            'auth-userid' => $this->config['auth_userid'] ?? '',
            'api-key'     => $this->config['api_key'] ?? '',
        ], $params));
        foreach ($repeat as $key => $vals) {
            foreach ((array)$vals as $v) {
                $q .= '&' . rawurlencode($key) . '=' . rawurlencode((string)$v);
            }
        }
        $url = $this->rcBase() . '/' . ltrim($path, '/') . '?' . $q;

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_SSL_VERIFYPEER => true]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ''); // LogicBoxes POST APIs take params in the query string
        }
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) throw new RuntimeException('LogicBoxes connection error: ' . $err);

        $data = json_decode($res, true);
        if (!is_array($data)) {
            // Some endpoints (customer signup, contact add) return a bare id
            $data = ['value' => is_numeric(trim((string)$res)) ? trim((string)$res) : $res];
        }
        if (($data['status'] ?? '') === 'ERROR' || isset($data['error'])) {
            $msg = (string) ($data['message'] ?? $data['error'] ?? 'unknown');
            if (in_array($this->provider, ['resellerclub', 'netearthone'], true)
                && (stripos($msg, 'access denied') !== false || stripos($msg, 'not authorized') !== false)) {
                $msg .= ' — this is the standard LogicBoxes response when your server\'s IP address is not on '
                     . 'the API IP Access list in your reseller control panel. Add your server\'s outbound IP '
                     . '(use the "Detect my server IP" button below) under Settings › API in your '
                     . ucfirst($this->provider) . ' account, then try again. Also double-check you are not '
                     . 'mixing sandbox/demo credentials with the live endpoint (or vice versa).';
            }
            throw new RuntimeException('Registrar error: ' . $msg);
        }
        return $data;
    }

    private function rcCheck(string $domain): array
    {
        [$sld, $tld] = array_pad(explode('.', $domain, 2), 2, 'com');
        $data  = $this->rcCall('domains/available.json', ['domain-name' => $sld], 'GET', ['tlds' => [$tld]]);
        $entry = $data[$domain] ?? (is_array(reset($data)) ? reset($data) : []);
        return ['domain' => $domain, 'available' => ($entry['status'] ?? '') === 'available', 'provider' => $this->provider];
    }

    private function rcGetInfo(string $domain): array
    {
        $data = $this->rcCall('domains/details-by-name.json', ['domain-name' => $domain, 'options' => 'All']);
        return [
            'domain'  => $domain,
            'status'  => $data['currentstatus'] ?? 'unknown',
            'expires' => isset($data['endtime']) ? date('Y-m-d', (int)$data['endtime']) : '',
        ];
    }

    /** TLD list + our wholesale 1-year costs from reseller-cost-price.json */
    private function lbTldPricing(): array
    {
        $cost = $this->rcCall('products/reseller-cost-price.json');
        $out  = [];
        foreach ($cost as $key => $data) {
            $tld = $this->lbKeyToTld((string)$key);
            if (!$tld || !is_array($data)) continue;
            $reg = $data['addnewdomain']['1']      ?? $data['addnewdomain'][1]      ?? null;
            $ren = $data['renewdomain']['1']       ?? $data['renewdomain'][1]       ?? null;
            $trf = $data['addtransferdomain']['1'] ?? $data['addtransferdomain'][1] ?? null;
            if ($reg === null && $ren === null && $trf === null) continue;
            $out[$tld] = [
                'register' => (float)($reg ?? 0),
                'renew'    => (float)($ren ?? ($reg ?? 0)),
                'transfer' => (float)($trf ?? ($reg ?? 0)),
            ];
        }
        ksort($out);
        return $out;
    }

    /** Map LogicBoxes product keys to TLDs (dotnet→net, domcno→com, …) */
    private function lbKeyToTld(string $key): ?string
    {
        $special = [
            'domcno' => 'com', 'dotcoop' => 'coop', 'dotnl' => 'nl',
            'centralnicuscom' => 'us.com', 'centralnicukcom' => 'uk.com', 'centralnicuknet' => 'uk.net',
            'centralniceucom' => 'eu.com', 'centralnicgbnet' => 'gb.net', 'centralniccncom' => 'cn.com',
            'centralnicdecom' => 'de.com', 'centralnicjpnet' => 'jp.net',
            'thirdleveldotname' => null, 'dotname' => 'name',
        ];
        if (array_key_exists($key, $special)) return $special[$key];
        if (str_starts_with($key, 'dot')) {
            $tld = substr($key, 3);
            return preg_match('/^[a-z0-9.]{2,}$/', $tld) ? $tld : null;
        }
        return null;
    }

    /** Full registration: ensure customer → add contact → register domain */
    private function lbRegister(string $domain, array $c, int $years, array $ns = []): array
    {
        $customerId = $this->lbEnsureCustomer($c);
        $contactId  = $this->lbAddContact($customerId, $c);

        if (!$ns) {
            $ns = array_filter(array_map('trim', explode(',', $this->config['default_ns'] ?? '')));
        }
        if (!$ns) {
            $host = $_SERVER['HTTP_HOST'] ?? 'orbitcloud.co.ke';
            $ns   = ['ns1.' . $host, 'ns2.' . $host];
        }

        $r = $this->rcCall('domains/register.json', [
            'domain-name'        => $domain,
            'years'              => $years,
            'customer-id'        => $customerId,
            'reg-contact-id'     => $contactId,
            'admin-contact-id'   => $contactId,
            'tech-contact-id'    => $contactId,
            'billing-contact-id' => $contactId,
            'invoice-option'     => 'NoInvoice',
            'protect-privacy'    => 'false',
        ], 'POST', ['ns' => array_slice(array_values($ns), 0, 6)]);

        $ok = (($r['actionstatus'] ?? $r['status'] ?? '') === 'Success') || !empty($r['entityid']);
        return [
            'success'     => $ok,
            'domain'      => $domain,
            'transaction' => (string)($r['entityid'] ?? ($r['eaqid'] ?? '')),
            'expires'     => date('Y-m-d', strtotime("+{$years} years")),
            'message'     => $ok ? 'Domain registered.' : (string)($r['actionstatusdesc'] ?? 'Registration was not confirmed by the registrar.'),
            'raw'         => $r,
        ];
    }

    /** Renew an existing LogicBoxes domain order by the given number of years. */
    private function lbRenew(string $domain, int $years): array
    {
        $d = $this->rcCall('domains/details-by-name.json', ['domain-name' => $domain, 'options' => 'OrderDetails']);
        $orderId = $d['orderid'] ?? null;
        if (!$orderId) {
            return ['success' => false, 'message' => 'Domain order not found at the registrar. It may need to be relinked — contact support.'];
        }
        // LogicBoxes requires the current expiry date as a safety check against
        // double-renewal (it rejects the call if this doesn't match their record).
        $expDate = isset($d['endtime']) ? date('d-m-Y', (int) $d['endtime']) : '';

        $r = $this->rcCall('domains/renew.json', [
            'order-id'       => $orderId,
            'years'          => $years,
            'exp-date'       => $expDate,
            'invoice-option' => 'NoInvoice',
        ], 'POST');

        $ok = (($r['actionstatus'] ?? $r['status'] ?? '') === 'Success') || !empty($r['entityid']) || !empty($r['value']);
        return [
            'success' => $ok,
            'domain'  => $domain,
            'message' => $ok ? 'Domain renewed for ' . $years . ' year(s).' : (string) ($r['actionstatusdesc'] ?? 'Renewal was not confirmed by the registrar.'),
            'raw'     => $r,
        ];
    }

    /**
     * Submit an inbound transfer request for a domain registered elsewhere.
     * Transfers are not instant — the losing registrar/registrant must
     * approve (or the 5–7 day auto-approval window must pass) before the
     * domain actually moves, so "success" here means "accepted for
     * processing", not "completed".
     */
    private function lbTransfer(string $domain, string $authCode, array $c, int $years, array $ns = []): array
    {
        $customerId = $this->lbEnsureCustomer($c);
        $contactId  = $this->lbAddContact($customerId, $c);

        $params = [
            'domain-name'        => $domain,
            'auth-code'          => $authCode,
            'customer-id'        => $customerId,
            'reg-contact-id'     => $contactId,
            'admin-contact-id'   => $contactId,
            'tech-contact-id'    => $contactId,
            'billing-contact-id' => $contactId,
            'invoice-option'     => 'NoInvoice',
            'protect-privacy'    => 'false',
        ];
        $repeat = $ns ? ['ns' => array_slice(array_values($ns), 0, 6)] : [];

        $r  = $this->rcCall('domains/transfer.json', $params, 'POST', $repeat);
        $ok = (($r['actionstatus'] ?? $r['status'] ?? '') === 'Success') || !empty($r['entityid']);
        return [
            'success'     => $ok,
            'domain'      => $domain,
            'transaction' => (string) ($r['entityid'] ?? ''),
            'message'     => $ok
                ? 'Transfer request submitted — this can take 5–7 days to complete once the current registrar approves it.'
                : (string) ($r['actionstatusdesc'] ?? 'The transfer request was rejected. Double-check the auth/EPP code and that the domain is unlocked at its current registrar.'),
            'raw' => $r,
        ];
    }

    /** Find the LogicBoxes customer by email, or create one. Returns customer id. */
    private function lbEnsureCustomer(array $c): string
    {
        $email = trim($c['email'] ?? '');
        if (!$email) throw new RuntimeException('A customer email is required to register a domain.');

        try {
            $d = $this->rcCall('customers/details.json', ['username' => $email]);
            if (!empty($d['customerid'])) return (string)$d['customerid'];
        } catch (\Throwable $e) {
            // not found — create below
        }

        [$cc, $phone] = $this->lbPhone($c['phone'] ?? '');
        $r = $this->rcCall('customers/signup.json', [
            'username'       => $email,
            'passwd'         => $this->lbPassword(),
            'name'           => trim(($c['first_name'] ?? 'Client') . ' ' . ($c['last_name'] ?? '')),
            'company'        => $c['company'] ?: 'N/A',
            'address-line-1' => $c['address'] ?? 'N/A',
            'city'           => $c['city'] ?? 'Nairobi',
            'state'          => $c['state'] ?? 'Nairobi',
            'country'        => strtoupper($c['country_code'] ?? 'KE'),
            'zipcode'        => $c['postcode'] ?? '00100',
            'phone-cc'       => $cc,
            'phone'          => $phone,
            'lang-pref'      => 'en',
        ], 'POST');

        $id = $r['value'] ?? ($r['customerid'] ?? null);
        if (!$id || !is_numeric((string)$id)) {
            throw new RuntimeException('Could not create registrar customer account: ' . json_encode($r));
        }
        return (string)$id;
    }

    /** Create a contact under the customer. Returns contact id. */
    private function lbAddContact(string $customerId, array $c): string
    {
        [$cc, $phone] = $this->lbPhone($c['phone'] ?? '');
        $r = $this->rcCall('contacts/add.json', [
            'name'           => trim(($c['first_name'] ?? 'Client') . ' ' . ($c['last_name'] ?? '')),
            'company'        => $c['company'] ?: 'N/A',
            'email'          => trim($c['email'] ?? ''),
            'address-line-1' => $c['address'] ?? 'N/A',
            'city'           => $c['city'] ?? 'Nairobi',
            'state'          => $c['state'] ?? 'Nairobi',
            'country'        => strtoupper($c['country_code'] ?? 'KE'),
            'zipcode'        => $c['postcode'] ?? '00100',
            'phone-cc'       => $cc,
            'phone'          => $phone,
            'customer-id'    => $customerId,
            'type'           => 'Contact',
        ], 'POST');

        $id = $r['value'] ?? null;
        if (!$id || !is_numeric((string)$id)) {
            throw new RuntimeException('Could not create registrar contact: ' . json_encode($r));
        }
        return (string)$id;
    }

    private function lbSetNameservers(string $domain, array $ns): array
    {
        $d = $this->rcCall('domains/details-by-name.json', ['domain-name' => $domain, 'options' => 'OrderDetails']);
        $orderId = $d['orderid'] ?? null;
        if (!$orderId) return ['success' => false, 'message' => 'Domain order not found at the registrar.'];
        $r = $this->rcCall('domains/modify-ns.json', ['order-id' => $orderId], 'POST', ['ns' => array_slice(array_values($ns), 0, 6)]);
        return ['success' => (($r['actionstatus'] ?? $r['status'] ?? '') === 'Success'), 'raw' => $r];
    }

    /** Split a phone like "+254 712 345678" into [cc, number] for LogicBoxes. */
    private function lbPhone(string $raw): array
    {
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '') return ['254', '700000000'];
        if (strlen($digits) > 10) {
            return [substr($digits, 0, strlen($digits) - 9), substr($digits, -9)];
        }
        return ['254', ltrim($digits, '0') ?: '700000000'];
    }

    private function lbPassword(): string
    {
        return 'Or' . bin2hex(random_bytes(5)) . '!7a'; // meets LogicBoxes complexity rules
    }

    // ══════════════════════════════════════════════════════════
    // CLOUDFLARE — Registrar + DNS (api.cloudflare.com/client/v4)
    // ══════════════════════════════════════════════════════════
    private function cfCall(string $path, string $method = 'GET', array $body = []): array
    {
        $ch = curl_init('https://api.cloudflare.com/client/v4' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . ($this->config['api_token'] ?? ''),
                'Content-Type: application/json',
            ],
        ]);
        if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) throw new RuntimeException("Cloudflare connection error: $err");
        $data = json_decode($res, true) ?? [];
        if (!($data['success'] ?? false)) {
            throw new RuntimeException('Cloudflare error: ' . ($data['errors'][0]['message'] ?? 'unknown'));
        }
        return $data;
    }
    private function cfTest(): array
    {
        $this->cfCall('/accounts/' . urlencode($this->config['account_id'] ?? ''));
        return ['success' => true, 'message' => 'Cloudflare token valid'];
    }
    private function cfGetInfo(string $domain): array
    {
        $data = $this->cfCall('/zones?name=' . urlencode($domain));
        $zone = $data['result'][0] ?? [];
        return [
            'domain'      => $domain,
            'status'      => $zone['status'] ?? 'unknown',
            'nameservers' => $zone['name_servers'] ?? [],
        ];
    }
}
