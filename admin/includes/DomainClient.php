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
            'resellerclub' => $this->rcCheck($domain),
            'cloudflare'   => ['domain' => $domain, 'available' => null, 'provider' => 'cloudflare', 'note' => 'Cloudflare Registrar has no public availability API — check in the Cloudflare dashboard.'],
            default        => throw new RuntimeException("Unsupported provider: {$this->provider}"),
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
                'resellerclub' => ['success' => true, 'message' => 'ResellerClub reachable', 'data' => $this->rcCall('domains/available.json', ['domain-name' => 'orbit', 'tlds' => 'com'])],
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
            'resellerclub' => $this->rcRegister($domain, $contact, $years, $nameservers),
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
            'resellerclub' => ['success' => false, 'message' => 'Renew ResellerClub domains from the reseller panel.'],
            'cloudflare'   => ['success' => false, 'message' => 'Cloudflare auto-renews at cost; manage in the dashboard.'],
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
            'resellerclub' => ['success' => false, 'message' => 'Set ResellerClub nameservers from the reseller panel.'],
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
            'resellerclub' => $this->rcGetInfo($domain),
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
    // RESELLERCLUB — LogicBoxes JSON API
    // ══════════════════════════════════════════════════════════
    private function rcBase(): string
    {
        return ($this->config['sandbox'] ?? true)
            ? 'https://test.httpapi.com/api'
            : 'https://httpapi.com/api';
    }
    private function rcCall(string $path, array $params = []): array
    {
        $q = http_build_query(array_merge([
            'auth-userid' => $this->config['auth_userid'] ?? '',
            'api-key'     => $this->config['api_key'] ?? '',
        ], $params));
        $ch = curl_init($this->rcBase() . '/' . ltrim($path, '/') . '?' . $q);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) throw new RuntimeException("ResellerClub connection error: $err");
        $data = json_decode($res, true) ?? [];
        if (($data['status'] ?? '') === 'ERROR') {
            throw new RuntimeException('ResellerClub error: ' . ($data['message'] ?? 'unknown'));
        }
        return $data;
    }
    private function rcCheck(string $domain): array
    {
        [$sld, $tld] = array_pad(explode('.', $domain, 2), 2, 'com');
        $data = $this->rcCall('domains/available.json', ['domain-name' => $sld, 'tlds' => $tld]);
        $entry = $data[$domain] ?? reset($data) ?: [];
        return ['domain' => $domain, 'available' => ($entry['status'] ?? '') === 'available', 'provider' => 'resellerclub'];
    }
    private function rcRegister(string $domain, array $c, int $years, array $ns = []): array
    {
        // ResellerClub registration requires a pre-created contact ID; surface a clear message
        return ['success' => false, 'message' => 'ResellerClub registration needs a contact ID created in the reseller panel first.'];
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
