<?php
/**
 * OrbitHost — Public domain availability + pricing API
 * Used by the website domain search (home page + domains page).
 *
 * GET ?q=<search term>   e.g. ?q=mybusiness or ?q=mybusiness.co.ke
 * → { ok, sld, results:[{ domain, tld, available, price, renew, currency }] }
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../admin/includes/config.php';
require_once __DIR__ . '/../admin/includes/db.php';
require_once __DIR__ . '/../admin/includes/providers/Provider.php';
require_once __DIR__ . '/../admin/includes/DomainClient.php';

function jout(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = trim($_GET['q'] ?? '');
if ($raw === '') jout(['ok' => false, 'error' => 'Enter a domain name to search.'], 400);

// Split "name.co.ke" into sld + requested tld
$raw = strtolower(preg_replace('/^https?:\/\//', '', $raw));
$raw = preg_replace('/[^a-z0-9.-]/', '', $raw);
$parts = explode('.', $raw, 2);
$sld = preg_replace('/[^a-z0-9-]/', '', $parts[0] ?? '');
$req_tld = isset($parts[1]) ? preg_replace('/[^a-z0-9.]/', '', $parts[1]) : '';

if ($sld === '' || strlen($sld) < 2) jout(['ok' => false, 'error' => 'Enter at least 2 characters.'], 400);

// Active TLDs with prices (limit for speed; requested TLD always included)
try {
    $rows = db()->query('SELECT tld, currency, register_price, renew_price FROM domain_tlds WHERE is_active = 1 ORDER BY sort_order, tld LIMIT 10')->fetchAll();
} catch (\Throwable $e) {
    jout(['ok' => false, 'error' => 'Domain pricing is not configured yet. Please contact support.'], 503);
}
if (!$rows) jout(['ok' => false, 'error' => 'Domain search is being set up. Please check back soon.'], 503);

$tld_prices = [];
foreach ($rows as $r) $tld_prices[$r['tld']] = $r;

// If the visitor typed a specific TLD we sell, put it first
if ($req_tld && isset($tld_prices[$req_tld])) {
    $tld_prices = [$req_tld => $tld_prices[$req_tld]] + $tld_prices;
}

$tlds = array_keys($tld_prices);

// Live availability via the active registrar (bulk where supported)
$availability = [];
$live = false;
try {
    $reg_key = Provider::activeFor('registrar');
    if ($reg_key) {
        $availability = Provider::registrar($reg_key)->checkBulk($sld, $tlds);
        $live = true;
    }
} catch (\Throwable $e) {
    $availability = []; // fall through — show prices with unknown status
}

$results = [];
foreach ($tld_prices as $tld => $p) {
    $domain = "$sld.$tld";
    $results[] = [
        'domain'    => $domain,
        'tld'       => $tld,
        'available' => $live ? ($availability[$domain] ?? null) : null,
        'price'     => (float)$p['register_price'],
        'renew'     => (float)$p['renew_price'],
        'currency'  => $p['currency'],
    ];
}

jout(['ok' => true, 'sld' => $sld, 'live' => $live, 'results' => $results]);
