<?php
/**
 * Orbit Cloud — public plan catalogue.
 *
 * Powers the pricing grids on the marketing pages (index.html and the
 * /hosting/* pages, email-hosting.html) so they always show what's
 * actually in Plans & Packages instead of the numbers that used to be
 * hardcoded into each page's HTML. Same idea as api/tld-pricing.php.
 *
 * ?category=shared limits the response to one category; without it every
 * category comes back, keyed by category name.
 *
 * Deliberately no-store: the whole point is that a plan edited in the
 * admin panel shows on the website immediately, so nothing between here
 * and the browser is allowed to hold a copy.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../admin/includes/config.php';
require_once __DIR__ . '/../admin/includes/db.php';
require_once __DIR__ . '/../admin/includes/Currency.php';

/**
 * URL-safe id for a plan: shared + "Business Pro" -> shared-business-pro.
 *
 * Names that already lead with their category ("Shared Starter") aren't
 * prefixed again — nobody wants to see ?plan=shared-shared-starter in the
 * address bar. Keep this identical to OrderCart::planSlug(), which resolves
 * these back into a plan.
 */
function plan_slug(string $category, string $name): string
{
    $cat  = strtolower(trim($category));
    $norm = strtolower(trim($name));
    $slug = str_starts_with($norm, $cat . ' ') ? $norm : $cat . '-' . $norm;
    return trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
}

try {
    // price_kes/setup_fee_kes are an auto-migration too — without this the
    // SELECT below dies on a database where nobody has opened an admin page
    // that adds them yet, and every pricing grid silently keeps its static
    // cards. Same call api/tld-pricing.php makes for the same reason.
    Currency::ensureSchema();

    // description/features/is_featured are added by admin/plans/index.php on
    // first visit — fall back cleanly if that hasn't happened yet.
    $has = function (string $col): bool {
        try { return (bool) db()->query("SHOW COLUMNS FROM services LIKE " . db()->quote($col))->fetch(); }
        catch (\Throwable $e) { return false; }
    };
    $details_ok  = $has('description');
    $featured_ok = $has('is_featured');

    $sql = 'SELECT id, name, category, billing_cycle, price, price_kes, setup_fee, setup_fee_kes'
         . ($details_ok  ? ', description, features' : ', NULL AS description, NULL AS features')
         . ($featured_ok ? ', is_featured'           : ', 0 AS is_featured')
         . ' FROM services WHERE is_active = 1';

    $params = [];
    $category = strtolower(trim($_GET['category'] ?? ''));
    if ($category !== '') {
        $sql .= ' AND category = ?';
        $params[] = $category;
    }
    // Cheapest first, which is the order the pricing grids read in
    // (Starter -> Business -> Pro).
    $sql .= ' ORDER BY category, price';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $features = array_values(array_filter(array_map(
            'trim',
            preg_split('/\r\n|\r|\n/', (string) ($r['features'] ?? ''))
        ), fn($l) => $l !== ''));

        $out[$r['category']][] = [
            'id'            => (int) $r['id'],
            'slug'          => plan_slug((string) $r['category'], (string) $r['name']),
            'name'          => $r['name'],
            'category'      => $r['category'],
            'billing_cycle' => $r['billing_cycle'],
            'price'         => (float) $r['price'],
            'price_kes'     => (float) ($r['price_kes'] ?? 0),
            'setup_fee'     => (float) ($r['setup_fee'] ?? 0),
            'setup_fee_kes' => (float) ($r['setup_fee_kes'] ?? 0),
            'description'   => (string) ($r['description'] ?? ''),
            'features'      => $features,
            'is_featured'   => !empty($r['is_featured']),
        ];
    }

    echo json_encode(['ok' => true, 'plans' => $out], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    // Table missing or DB down — the pages keep their static cards.
    echo json_encode(['ok' => false, 'plans' => new stdClass()]);
}
