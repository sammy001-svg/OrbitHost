<?php
/**
 * Orbit Cloud — add-on services offered alongside a hosting package.
 *
 * Managed in admin/addons/, offered on step 1 of the order flow
 * (portal/order/index.php) and priced into the same invoice as the plan.
 */
require_once __DIR__ . '/db.php';

final class ServiceAddon
{
    /** Idempotent — called by both the admin page and the order flow. */
    public static function ensureSchema(): bool
    {
        static $done = null;
        if ($done !== null) return $done;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS service_addons (
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name           VARCHAR(150) NOT NULL,
                description    TEXT         DEFAULT NULL,
                billing_cycle  ENUM('monthly','annual','one_time') NOT NULL DEFAULT 'monthly',
                price          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                price_kes      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                categories     VARCHAR(255) DEFAULT NULL,
                is_active      TINYINT(1)   NOT NULL DEFAULT 1,
                is_preselected TINYINT(1)   NOT NULL DEFAULT 0,
                sort_order     INT          NOT NULL DEFAULT 100,
                created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return $done = true;
        } catch (\Throwable $e) {
            return $done = false;
        }
    }

    /**
     * Active add-ons offered with $category. A row with an empty
     * `categories` list is offered everywhere; otherwise the category has
     * to appear in its comma-separated list.
     */
    public static function forCategory(string $category): array
    {
        if (!self::ensureSchema()) return [];
        try {
            $rows = db()->query('SELECT * FROM service_addons WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
        return array_values(array_filter($rows, function ($r) use ($category) {
            $cats = trim((string) $r['categories']);
            if ($cats === '') return true;
            return in_array($category, array_map('trim', explode(',', $cats)), true);
        }));
    }

    /** Look up a set of add-ons by id, preserving catalogue order. */
    public static function byIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !self::ensureSchema()) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = db()->prepare("SELECT * FROM service_addons WHERE id IN ($in) ORDER BY sort_order, name");
            $stmt->execute($ids);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Price of one add-on in the checkout currency. */
    public static function price(array $addon, string $currency): float
    {
        return (float) ($currency === 'KES' ? ($addon['price_kes'] ?? 0) : ($addon['price'] ?? 0));
    }

    /** "/mo", "/yr" or "" — matches how the plan lines read on the summary. */
    public static function cycleSuffix(string $cycle): string
    {
        return $cycle === 'monthly' ? '/mo' : ($cycle === 'annual' ? '/yr' : '');
    }
}
