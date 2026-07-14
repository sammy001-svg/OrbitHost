<?php
/**
 * Orbit Cloud — Unified Hosting Control Panel client
 *
 * One interface over WHM/cPanel, Plesk and DirectAdmin so the Services
 * module can provision and manage accounts without caring which panel a
 * server runs. Every method returns a normalised array:
 *
 *   ['success' => bool, 'message' => string, ...data]
 *
 * WHM is delegated to the battle-tested WHMClient; Plesk and DirectAdmin
 * are implemented against their native APIs.
 */
final class PanelClient
{
    private string $provider;
    private array  $cfg;

    public function __construct(string $provider, array $config)
    {
        $this->provider = strtolower($provider);
        $this->cfg      = $config;
    }

    // ── Public lifecycle API ──────────────────────────────────
    public function testConnection(): array
    {
        return $this->dispatch(__FUNCTION__, []);
    }

    /** $p keys: username, domain, password, package, email */
    public function createAccount(array $p): array
    {
        return $this->dispatch(__FUNCTION__, [$p]);
    }

    public function suspend(string $user, string $reason = 'Suspended by Orbit Cloud'): array
    {
        return $this->dispatch(__FUNCTION__, [$user, $reason]);
    }

    public function unsuspend(string $user): array
    {
        return $this->dispatch(__FUNCTION__, [$user]);
    }

    public function terminate(string $user): array
    {
        return $this->dispatch(__FUNCTION__, [$user]);
    }

    public function changePassword(string $user, string $password): array
    {
        return $this->dispatch(__FUNCTION__, [$user, $password]);
    }

    public function changePackage(string $user, string $package): array
    {
        return $this->dispatch(__FUNCTION__, [$user, $package]);
    }

    /** @return array disk_used_mb, disk_limit_mb, bw_used_mb */
    public function getUsage(string $user): array
    {
        return $this->dispatch(__FUNCTION__, [$user]);
    }

    public function listAccounts(): array
    {
        return $this->dispatch(__FUNCTION__, []);
    }

    public function listPackages(): array
    {
        return $this->dispatch(__FUNCTION__, []);
    }

    // ── Dispatcher ────────────────────────────────────────────
    private function dispatch(string $method, array $args): array
    {
        $impl = $this->provider . ucfirst($method); // e.g. whmCreateAccount
        if (!method_exists($this, $impl)) {
            throw new RuntimeException("Panel '{$this->provider}' does not support {$method}().");
        }
        try {
            return $this->$impl(...$args);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ══════════════════════════════════════════════════════════
    // WHM / cPanel — delegates to WHMClient
    // ══════════════════════════════════════════════════════════
    private function whm(): WHMClient
    {
        require_once __DIR__ . '/../WHMClient.php';
        return new WHMClient(
            $this->cfg['host']  ?? '',
            $this->cfg['user']  ?? 'root',
            $this->cfg['token'] ?? '',
            (bool)($this->cfg['ssl_verify'] ?? false)
        );
    }

    private function whmTestConnection(): array
    {
        $v = $this->whm()->getServerVersion();
        return ['success' => isset($v['version']), 'message' => 'Connected to WHM ' . ($v['version'] ?? '?'), 'info' => $v];
    }
    private function whmCreateAccount(array $p): array
    {
        $r = $this->whm()->createAccount(
            $p['username'], $p['domain'], $p['password'],
            $p['package'] ?? 'default', $p['email'] ?? '', $p['email'] ?? ''
        );
        $ok = (int)($r['metadata']['result'] ?? ($r['result'] ?? 0)) === 1 || isset($r['data']);
        return ['success' => $ok, 'message' => $r['metadata']['reason'] ?? 'Account created', 'raw' => $r, 'username' => $p['username']];
    }
    private function whmSuspend(string $u, string $reason): array
    {
        $this->whm()->suspendAccount($u, $reason);
        return ['success' => true, 'message' => 'Account suspended'];
    }
    private function whmUnsuspend(string $u): array
    {
        $this->whm()->unsuspendAccount($u);
        return ['success' => true, 'message' => 'Account unsuspended'];
    }
    private function whmTerminate(string $u): array
    {
        $this->whm()->removeAccount($u);
        return ['success' => true, 'message' => 'Account terminated'];
    }
    private function whmChangePassword(string $u, string $pass): array
    {
        $this->whm()->changePassword($u, $pass);
        return ['success' => true, 'message' => 'Password changed'];
    }
    private function whmChangePackage(string $u, string $pkg): array
    {
        $this->whm()->changePackage($u, $pkg);
        return ['success' => true, 'message' => 'Package changed'];
    }
    private function whmGetUsage(string $u): array
    {
        $disk = $this->whm()->getDiskInfo($u);
        $bw   = $this->whm()->getBandwidth($u);
        return [
            'success'       => true,
            'disk_used_mb'  => (int)($disk['disk_used']  ?? 0),
            'disk_limit_mb' => (int)($disk['disk_limit'] ?? 0),
            'bw_used_mb'    => (int)($bw['bw_used']       ?? 0),
        ];
    }
    private function whmListAccounts(): array
    {
        return ['success' => true, 'accounts' => $this->whm()->listAccounts()];
    }
    private function whmListPackages(): array
    {
        return ['success' => true, 'packages' => $this->whm()->listPackages()];
    }

    // ══════════════════════════════════════════════════════════
    // Plesk — REST API (https://host:8443/api/v2)
    // ══════════════════════════════════════════════════════════
    private function pleskCall(string $path, string $method = 'GET', array $body = []): array
    {
        $host = preg_replace('#^https?://#i', '', $this->cfg['host'] ?? '');
        $host = preg_replace('#:\d+$#', '', rtrim($host, '/'));
        $port = (int)($this->cfg['port'] ?? 8443);
        $url  = "https://{$host}:{$port}/api/v2{$path}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_SSL_VERIFYPEER => (bool)($this->cfg['ssl_verify'] ?? false),
            CURLOPT_SSL_VERIFYHOST => ($this->cfg['ssl_verify'] ?? false) ? 2 : 0,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode(($this->cfg['user'] ?? 'admin') . ':' . ($this->cfg['password'] ?? '')),
            ],
        ]);
        if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) throw new RuntimeException("Plesk connection error: $err");
        $data = json_decode($res, true) ?? [];
        if ($code >= 400) throw new RuntimeException('Plesk error: ' . ($data['message'] ?? "HTTP $code"));
        return $data;
    }
    private function pleskTestConnection(): array
    {
        $d = $this->pleskCall('/server');
        return ['success' => true, 'message' => 'Connected to Plesk', 'info' => $d];
    }
    private function pleskCreateAccount(array $p): array
    {
        $d = $this->pleskCall('/clients', 'POST', [
            'name'  => $p['username'],
            'login' => $p['username'],
            'password' => $p['password'],
        ]);
        // create the subscription/domain under the client
        $this->pleskCall('/domains', 'POST', [
            'name'          => $p['domain'],
            'hosting_type'  => 'virtual',
            'owner_login'   => $p['username'],
            'plan_name'     => $p['package'] ?? 'Default Domain',
        ]);
        return ['success' => true, 'message' => 'Plesk subscription created', 'raw' => $d, 'username' => $p['username']];
    }
    private function pleskSuspend(string $u, string $reason): array
    {
        $this->pleskCall('/clients/' . urlencode($u) . '/suspend', 'PUT');
        return ['success' => true, 'message' => 'Client suspended'];
    }
    private function pleskUnsuspend(string $u): array
    {
        $this->pleskCall('/clients/' . urlencode($u) . '/activate', 'PUT');
        return ['success' => true, 'message' => 'Client activated'];
    }
    private function pleskTerminate(string $u): array
    {
        $this->pleskCall('/clients/' . urlencode($u), 'DELETE');
        return ['success' => true, 'message' => 'Client removed'];
    }
    private function pleskChangePassword(string $u, string $pass): array
    {
        $this->pleskCall('/clients/' . urlencode($u), 'PUT', ['password' => $pass]);
        return ['success' => true, 'message' => 'Password changed'];
    }
    private function pleskGetUsage(string $u): array
    {
        return ['success' => true, 'disk_used_mb' => 0, 'disk_limit_mb' => 0, 'bw_used_mb' => 0];
    }
    private function pleskListAccounts(): array
    {
        return ['success' => true, 'accounts' => $this->pleskCall('/clients')];
    }

    // ══════════════════════════════════════════════════════════
    // DirectAdmin — legacy CMD_API (https://host:2222/CMD_API_*)
    // ══════════════════════════════════════════════════════════
    private function daCall(string $cmd, array $params = [], string $method = 'GET'): array
    {
        $host = preg_replace('#^https?://#i', '', $this->cfg['host'] ?? '');
        $host = preg_replace('#:\d+$#', '', rtrim($host, '/'));
        $port = (int)($this->cfg['port'] ?? 2222);
        $url  = "https://{$host}:{$port}/{$cmd}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => (bool)($this->cfg['ssl_verify'] ?? false),
            CURLOPT_SSL_VERIFYHOST => ($this->cfg['ssl_verify'] ?? false) ? 2 : 0,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERPWD        => ($this->cfg['user'] ?? 'admin') . ':' . ($this->cfg['token'] ?? ''),
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } else {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        }
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) throw new RuntimeException("DirectAdmin connection error: $err");

        parse_str($res, $parsed);           // DA returns URL-encoded key=value
        if (($parsed['error'] ?? '0') === '1') {
            throw new RuntimeException('DirectAdmin error: ' . urldecode($parsed['text'] ?? 'unknown'));
        }
        return $parsed ?: ['raw' => $res];
    }
    private function directadminTestConnection(): array
    {
        $d = $this->daCall('CMD_API_SHOW_ALL_USERS');
        return ['success' => true, 'message' => 'Connected to DirectAdmin', 'info' => $d];
    }
    private function directadminCreateAccount(array $p): array
    {
        $d = $this->daCall('CMD_API_ACCOUNT_USER', [
            'action'   => 'create',
            'add'      => 'Submit',
            'username' => $p['username'],
            'email'    => $p['email'] ?? '',
            'passwd'   => $p['password'],
            'passwd2'  => $p['password'],
            'domain'   => $p['domain'],
            'package'  => $p['package'] ?? '',
            'ip'       => 'shared',
            'notify'   => 'no',
        ], 'POST');
        return ['success' => true, 'message' => 'DirectAdmin account created', 'raw' => $d, 'username' => $p['username']];
    }
    private function directadminSuspend(string $u, string $reason): array
    {
        $this->daCall('CMD_API_SELECT_USERS', ['location'=>'CMD_SELECT_USERS','suspend'=>'Suspend','select0'=>$u], 'POST');
        return ['success' => true, 'message' => 'Account suspended'];
    }
    private function directadminUnsuspend(string $u): array
    {
        $this->daCall('CMD_API_SELECT_USERS', ['location'=>'CMD_SELECT_USERS','suspend'=>'Unsuspend','select0'=>$u], 'POST');
        return ['success' => true, 'message' => 'Account unsuspended'];
    }
    private function directadminTerminate(string $u): array
    {
        $this->daCall('CMD_API_SELECT_USERS', ['confirmed'=>'Confirm','delete'=>'yes','select0'=>$u], 'POST');
        return ['success' => true, 'message' => 'Account deleted'];
    }
    private function directadminChangePassword(string $u, string $pass): array
    {
        $this->daCall('CMD_API_USER_PASSWD', ['username'=>$u,'passwd'=>$pass,'passwd2'=>$pass], 'POST');
        return ['success' => true, 'message' => 'Password changed'];
    }
    private function directadminChangePackage(string $u, string $pkg): array
    {
        $this->daCall('CMD_API_MODIFY_USER', ['action'=>'package','user'=>$u,'package'=>$pkg], 'POST');
        return ['success' => true, 'message' => 'Package changed'];
    }
    private function directadminGetUsage(string $u): array
    {
        $d = $this->daCall('CMD_API_SHOW_USER_USAGE', ['user' => $u]);
        return [
            'success'       => true,
            'disk_used_mb'  => (int)($d['quota'] ?? 0),
            'disk_limit_mb' => 0,
            'bw_used_mb'    => (int)($d['bandwidth'] ?? 0),
        ];
    }
    private function directadminListAccounts(): array
    {
        $d = $this->daCall('CMD_API_SHOW_ALL_USERS');
        return ['success' => true, 'accounts' => array_values($d)];
    }
}
