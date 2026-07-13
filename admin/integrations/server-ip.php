<?php
/**
 * OrbitHost — detects this server's outbound public IP address.
 * Used by the Providers hub so admins can copy the exact IP that needs
 * whitelisting in a registrar's API access list (e.g. NetEarthOne /
 * ResellerClub, which reject requests from non-whitelisted IPs with
 * "Access Denied: You are not authorized to perform this action").
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

auth_check();
header('Content-Type: application/json');

function fetch_ip(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true]);
    $res = curl_exec($ch);
    curl_close($ch);
    $ip = trim((string) $res);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

$ip = fetch_ip('https://api.ipify.org') ?? fetch_ip('https://ifconfig.me/ip');

echo $ip
    ? json_encode(['ok' => true, 'ip' => $ip])
    : json_encode(['ok' => false, 'error' => 'Could not reach an external IP-detection service from this server. Check with your host for the server\'s outbound IP, or run "curl https://api.ipify.org" from an SSH session.']);
