<?php
/**
 * OrbitHost — public TLD pricing list (no availability check).
 * Powers the static "Popular Domain Extensions" comparison table on
 * domains.html, so it always reflects what's actually set in
 * Integrations > Domains > TLD Pricing instead of hardcoded numbers.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=300');

require_once __DIR__ . '/../admin/includes/config.php';
require_once __DIR__ . '/../admin/includes/db.php';

try {
    $rows = db()->query(
        'SELECT tld, currency, register_price, renew_price, transfer_price
         FROM domain_tlds WHERE is_active = 1 ORDER BY sort_order, tld LIMIT 12'
    )->fetchAll();
    echo json_encode(['ok' => true, 'tlds' => $rows], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    // Table not migrated yet — front-end falls back to its static rows
    echo json_encode(['ok' => false, 'tlds' => []]);
}
