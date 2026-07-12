<?php
/**
 * Multi-provider Domain Registrar Client
 * Supports: Namecheap, GoDaddy, Manual
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
            'namecheap' => $this->ncCheck($domain),
            'godaddy'   => $this->gdCheck($domain),
            default     => throw new RuntimeException("Unsupported provider: {$this->provider}"),
        };
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
            'namecheap' => $this->ncRegister($domain, $contact, $years, $nameservers),
            'godaddy'   => $this->gdRegister($domain, $contact, $years),
            default     => throw new RuntimeException("Unsupported provider: {$this->provider}"),
        };
    }

    // ── Domain renewal ────────────────────────────────────────
    public function renew(string $domain, int $years = 1): array
    {
        return match($this->provider) {
            'namecheap' => $this->ncRenew($domain, $years),
            'godaddy'   => ['success' => false, 'message' => 'Use GoDaddy panel to renew.'],
            default     => throw new RuntimeException("Unsupported provider: {$this->provider}"),
        };
    }

    // ── Nameserver update ─────────────────────────────────────
    public function setNameservers(string $domain, array $nameservers): array
    {
        return match($this->provider) {
            'namecheap' => $this->ncSetNameservers($domain, $nameservers),
            'godaddy'   => $this->gdSetNameservers($domain, $nameservers),
            default     => throw new RuntimeException("Unsupported provider: {$this->provider}"),
        };
    }

    // ── Get domain info ───────────────────────────────────────
    public function getInfo(string $domain): array
    {
        return match($this->provider) {
            'namecheap' => $this->ncGetInfo($domain),
            'godaddy'   => $this->gdGetInfo($domain),
            default     => throw new RuntimeException("Unsupported provider: {$this->provider}"),
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
}
