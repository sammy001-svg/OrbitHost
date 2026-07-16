<?php
/**
 * Orbit Cloud — discount coupon codes for the hosting/service checkout
 * (portal/order.php). Percent-off or fixed-amount-off, with an optional
 * minimum order, category restriction, usage cap, and expiry date.
 */
class Coupon
{
    public static function ensureTable(): bool
    {
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS coupons (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code          VARCHAR(40)  NOT NULL UNIQUE,
                type          ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
                amount        DECIMAL(10,2) NOT NULL DEFAULT 0,
                amount_kes    DECIMAL(10,2) NOT NULL DEFAULT 0,
                min_order     DECIMAL(10,2) NOT NULL DEFAULT 0,
                min_order_kes DECIMAL(10,2) NOT NULL DEFAULT 0,
                categories    VARCHAR(255) DEFAULT NULL,
                max_uses      INT UNSIGNED DEFAULT NULL,
                used_count    INT UNSIGNED NOT NULL DEFAULT 0,
                expires_at    DATE DEFAULT NULL,
                is_active     TINYINT(1) NOT NULL DEFAULT 1,
                created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Auto-migrate the discount-tracking columns onto invoices. */
    public static function ensureInvoiceColumns(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $col = db()->query("SHOW COLUMNS FROM invoices LIKE 'coupon_code'")->fetch();
            if (!$col) {
                db()->exec("ALTER TABLE invoices
                    ADD COLUMN coupon_code     VARCHAR(40) DEFAULT NULL,
                    ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
            }
        } catch (\Throwable $e) {
            // no ALTER privilege — discounts still apply, just won't be itemized on the invoice
        }
    }

    /** Fetch a coupon by code (case-insensitive), regardless of status — callers check validate(). */
    public static function find(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') return null;
        try {
            $stmt = db()->prepare('SELECT * FROM coupons WHERE UPPER(code) = UPPER(?) LIMIT 1');
            $stmt->execute([$code]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether $coupon can be applied to an order of $subtotal (in
     * $currency) for a plan in $category. Returns ['ok'=>bool,'message'=>string] —
     * message is a short, client-facing reason whenever ok is false.
     */
    public static function validate(array $coupon, float $subtotal, string $currency, string $category): array
    {
        if (empty($coupon['is_active'])) {
            return ['ok' => false, 'message' => 'This coupon is no longer active.'];
        }
        if (!empty($coupon['expires_at']) && strtotime($coupon['expires_at']) < strtotime(date('Y-m-d'))) {
            return ['ok' => false, 'message' => 'This coupon has expired.'];
        }
        if ($coupon['max_uses'] !== null && (int) $coupon['used_count'] >= (int) $coupon['max_uses']) {
            return ['ok' => false, 'message' => 'This coupon has reached its usage limit.'];
        }
        if (!empty($coupon['categories'])) {
            $cats = array_map('trim', explode(',', $coupon['categories']));
            if (!in_array($category, $cats, true)) {
                return ['ok' => false, 'message' => "This coupon doesn't apply to this plan."];
            }
        }
        $min = strtoupper($currency) === 'KES' ? (float) $coupon['min_order_kes'] : (float) $coupon['min_order'];
        if ($min > 0 && $subtotal < $min) {
            return ['ok' => false, 'message' => 'This coupon needs a minimum order of ' . format_money($min, $currency) . '.'];
        }
        return ['ok' => true, 'message' => ''];
    }

    /** Discount amount in $currency for $subtotal — never more than the subtotal itself. */
    public static function discountFor(array $coupon, float $subtotal, string $currency): float
    {
        if ($coupon['type'] === 'percent') {
            $discount = $subtotal * ((float) $coupon['amount'] / 100);
        } else {
            $discount = strtoupper($currency) === 'KES' ? (float) $coupon['amount_kes'] : (float) $coupon['amount'];
        }
        return round(min(max($discount, 0), $subtotal), 2);
    }

    public static function redeem(int $id): void
    {
        try {
            db()->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?')->execute([$id]);
        } catch (\Throwable $e) {
            // non-fatal — worst case the usage counter under-counts
        }
    }
}
