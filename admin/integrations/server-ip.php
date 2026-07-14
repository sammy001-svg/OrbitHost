<?php
/**
 * Orbit Cloud — detects this server's outbound IP address for registrar
 * IP-whitelisting (NetEarthOne / ResellerClub reject non-whitelisted
 * requests with "Access Denied: You are not authorized to perform this
 * action").
 *
 * A generic third-party "what's my IP" check can be misleading: if the
 * server has both IPv4 and IPv6 connectivity, or a hosting environment
 * routes different destinations through different source IPs, the IP
 * that echo service reports may not be the IP the registrar's API
 * actually sees. To be definitive, we open a real connection to the
 * registrar's own endpoint (respecting its sandbox/live + custom API
 * base config) and read cURL's CURLINFO_LOCAL_IP — the true source IP
 * of that specific connection — forced to IPv4, since legacy
 * IP-whitelist systems like LogicBoxes' are IPv4-only.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/providers/Provider.php';

auth_check();
header('Content-Type: application/json');

function probe(string $url, bool $forceV4 = true): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_NOBODY         => true, // HEAD-only — we just need the connection, not the body
    ];
    if ($forceV4 && defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
        $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $localIp = curl_getinfo($ch, CURLINFO_LOCAL_IP) ?: null;
    $err     = curl_error($ch);
    if (is_resource($ch)) {
        curl_close($ch);
    }
    return ['ip' => $localIp, 'error' => $err];
}

// Which registrar endpoint actually matters right now?
$target_host = null;
$provider_label = null;
foreach (['netearthone', 'resellerclub'] as $key) {
    $cfg = Provider::config($key);
    if (!Provider::isActive($key) && empty($cfg['auth_userid'])) continue; // not set up at all — skip
    $sandbox = !empty($cfg['sandbox']);
    if ($sandbox) {
        $target_host = 'https://test.httpapi.com/api/domains/available.json';
    } else {
        $base = rtrim(trim($cfg['api_base'] ?? ''), '/');
        $target_host = ($base !== '' ? $base : 'https://httpapi.com/api') . '/domains/available.json';
        if (!preg_match('#^https?://#i', $target_host)) $target_host = 'https://' . $target_host;
    }
    $provider_label = $key;
    break;
}
if (!$target_host) $target_host = 'https://httpapi.com/api'; // no registrar configured yet — probe the generic endpoint anyway

$direct = probe($target_host, true);
$echo   = probe('https://api.ipify.org', true);

if (!$direct['ip'] && !$echo['ip']) {
    echo json_encode(['ok' => false, 'error' => 'Could not open an outbound connection at all from this server. Check with your host whether outbound HTTPS is blocked by a firewall.']);
    exit;
}

$ip = $direct['ip'] ?: $echo['ip'];
$mismatch = $direct['ip'] && $echo['ip'] && $direct['ip'] !== $echo['ip'];

echo json_encode([
    'ok'       => true,
    'ip'       => $ip,
    'source'   => $direct['ip'] ? 'direct connection to ' . ($provider_label ?: 'the registrar endpoint') : 'generic IP-echo service (direct probe failed: ' . $direct['error'] . ')',
    'mismatch' => $mismatch,
    'echo_ip'  => $echo['ip'],
]);
