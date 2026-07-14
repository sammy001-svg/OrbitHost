<?php
/**
 * Orbit Cloud — visitor currency resolution (KES for Kenya, USD elsewhere)
 *
 * Called once per visitor by js/currency.js on first page load (no cookie
 * yet), and by the navbar KSH/USD toggle (explicit override, ?set=). Every
 * other page load just reads the cookie Currency::remember() already set —
 * this endpoint is the only place that pays the geo-IP lookup's latency.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../admin/includes/config.php';
require_once __DIR__ . '/../admin/includes/db.php';
require_once __DIR__ . '/../admin/includes/Currency.php';

$set = strtoupper(trim($_GET['set'] ?? ''));

if ($set === 'KES' || $set === 'USD') {
    Currency::remember($set);
    echo json_encode(['ok' => true, 'currency' => $set, 'source' => 'manual']);
    exit;
}

$existing = $_COOKIE[Currency::COOKIE_NAME] ?? '';
if ($existing === 'KES' || $existing === 'USD') {
    echo json_encode(['ok' => true, 'currency' => $existing, 'source' => 'cookie']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$currency = Currency::detectAndRemember($ip);
echo json_encode(['ok' => true, 'currency' => $currency, 'source' => 'geoip']);
