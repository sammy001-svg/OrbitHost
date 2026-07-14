<?php
/**
 * Orbit Cloud — public TLD pricing list (no availability check).
 * Powers the static "Popular Domain Extensions" comparison table on
 * domains.html, so it always reflects what's actually set in
 * Integrations > Domains > TLD Pricing instead of hardcoded numbers.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=300');

require_once __DIR__ . '/../admin/includes/config.php';
require_once __DIR__ . '/../admin/includes/db.php';
require_once __DIR__ . '/../admin/includes/Currency.php';

try {
    Currency::ensureSchema();
    $rows = db()->query(
        'SELECT tld, register_price_usd, register_price_kes, renew_price_usd, renew_price_kes,
                transfer_price_usd, transfer_price_kes
         FROM domain_tlds WHERE is_active = 1 ORDER BY sort_order, tld LIMIT 12'
    )->fetchAll();
    echo json_encode(['ok' => true, 'currency' => Currency::current(), 'tlds' => $rows], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    // Table not migrated yet — front-end falls back to its static rows
    echo json_encode(['ok' => false, 'tlds' => []]);
}
