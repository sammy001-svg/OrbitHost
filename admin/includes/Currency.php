<?php
/**
 * Orbit Cloud — dual-currency support (KES / USD)
 *
 * Design: admin enters a REAL price in both currencies for every plan and
 * TLD — this is not live FX conversion, so there's no exchange-rate API or
 * daily-rate table to keep in sync. SEED_RATE below is only used once, to
 * pre-fill a starting KES value when a column is first added (existing
 * USD prices × ~130), so the feature isn't blank out of the box. Admins
 * are expected to correct these to their actual KES pricing afterward.
 *
 * Visitor currency resolution order:
 *   1. `orbit_currency` cookie — set either by the navbar toggle (explicit
 *      choice, persists indefinitely) or by a prior geo-IP lookup this
 *      session (implicit, so we don't re-query the geo-IP service on
 *      every request).
 *   2. Geo-IP lookup (Kenya -> KES, everywhere else -> USD), via the free
 *      ip-api.com endpoint, 2s timeout. Any failure (offline, rate-limited,
 *      local/private IP in dev) defaults to USD rather than blocking the
 *      page or guessing wrong in the more visible direction.
 */
final class Currency
{
    public const DEFAULT_CURRENCY = 'USD';
    public const SEED_RATE        = 130; // approx KES per 1 USD, seed-only — see docblock above
    public const COOKIE_NAME      = 'orbit_currency';
    public const COOKIE_DAYS      = 180;

    /** Auto-migrate the dual-currency columns onto services, domain_tlds, invoices. */
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            $col = db()->query("SHOW COLUMNS FROM services LIKE 'price_kes'")->fetch();
            if (!$col) {
                db()->exec("ALTER TABLE services
                    ADD COLUMN price_kes     DECIMAL(10,2) DEFAULT NULL,
                    ADD COLUMN setup_fee_kes DECIMAL(10,2) DEFAULT NULL");
            }
            // One-time seed for any row that doesn't have a KES price yet.
            db()->exec('UPDATE services SET price_kes = ROUND(price * ' . self::SEED_RATE . ', -1) WHERE price_kes IS NULL');
            db()->exec('UPDATE services SET setup_fee_kes = ROUND(setup_fee * ' . self::SEED_RATE . ', -1) WHERE setup_fee_kes IS NULL AND setup_fee > 0');
            db()->exec('UPDATE services SET setup_fee_kes = 0 WHERE setup_fee_kes IS NULL');
        } catch (\Throwable $e) {
            // no ALTER privilege — dual pricing simply won't be available until schema is added manually
        }

        try {
            $col = db()->query("SHOW COLUMNS FROM domain_tlds LIKE 'register_price_usd'")->fetch();
            if (!$col) {
                db()->exec("ALTER TABLE domain_tlds
                    ADD COLUMN register_price_usd DECIMAL(10,2) DEFAULT NULL,
                    ADD COLUMN register_price_kes DECIMAL(10,2) DEFAULT NULL,
                    ADD COLUMN transfer_price_usd DECIMAL(10,2) DEFAULT NULL,
                    ADD COLUMN transfer_price_kes DECIMAL(10,2) DEFAULT NULL,
                    ADD COLUMN renew_price_usd    DECIMAL(10,2) DEFAULT NULL,
                    ADD COLUMN renew_price_kes    DECIMAL(10,2) DEFAULT NULL");
            }
            // Backfill from whichever single currency each row was already priced in.
            foreach (['register', 'transfer', 'renew'] as $p) {
                db()->exec("UPDATE domain_tlds SET {$p}_price_usd = {$p}_price WHERE {$p}_price_usd IS NULL AND currency = 'USD'");
                db()->exec("UPDATE domain_tlds SET {$p}_price_kes = {$p}_price WHERE {$p}_price_kes IS NULL AND currency = 'KES'");
                db()->exec("UPDATE domain_tlds SET {$p}_price_kes = ROUND({$p}_price_usd * " . self::SEED_RATE . ", -1) WHERE {$p}_price_kes IS NULL AND {$p}_price_usd IS NOT NULL");
                db()->exec("UPDATE domain_tlds SET {$p}_price_usd = ROUND({$p}_price_kes / " . self::SEED_RATE . ", 2) WHERE {$p}_price_usd IS NULL AND {$p}_price_kes IS NOT NULL");
            }
        } catch (\Throwable $e) {
            // no ALTER privilege — TLD dual pricing simply won't be available until schema is added manually
        }

        // invoices / orders / client_services all need to remember which
        // currency they were actually billed in — without this, an admin
        // dashboard SUM(total)/SUM(amount) would silently blend USD and KES
        // figures into one meaningless number the moment a single KES order
        // exists. NULL means "billed before this column existed", which
        // every aggregate query below treats as the site default (USD).
        foreach (['invoices', 'orders', 'client_services'] as $table) {
            try {
                $col = db()->query("SHOW COLUMNS FROM {$table} LIKE 'currency'")->fetch();
                if (!$col) {
                    db()->exec("ALTER TABLE {$table} ADD COLUMN currency VARCHAR(10) DEFAULT NULL");
                }
            } catch (\Throwable $e) {
                // no ALTER privilege — this table's rows fall back to the site default currency
            }
        }
    }

    /** The visitor's resolved currency: 'KES' or 'USD'. Never queries geo-IP itself — see detectAndRemember(). */
    public static function current(): string
    {
        $c = strtoupper((string) ($_COOKIE[self::COOKIE_NAME] ?? ''));
        return $c === 'KES' ? 'KES' : self::DEFAULT_CURRENCY;
    }

    /**
     * Resolve + persist a currency for a first-time visitor (no cookie yet).
     * Called only from api/geo-currency.php — regular pages just read the
     * cookie via current() and never pay the geo-IP lookup's latency.
     */
    public static function detectAndRemember(string $ip): string
    {
        $currency = self::lookupCountryIsKenya($ip) ? 'KES' : self::DEFAULT_CURRENCY;
        self::remember($currency);
        return $currency;
    }

    /** Persist an explicit choice (navbar toggle) or a geo-IP result, for COOKIE_DAYS. */
    public static function remember(string $currency): void
    {
        $currency = strtoupper($currency) === 'KES' ? 'KES' : 'USD';
        setcookie(self::COOKIE_NAME, $currency, [
            'expires'  => time() + self::COOKIE_DAYS * 86400,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false, // the navbar toggle's own JS needs to read/flip it client-side too
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE_NAME] = $currency; // so the rest of this same request sees it immediately
    }

    /** Best-effort geo-IP: true only when the free lookup positively identifies Kenya. */
    private static function lookupCountryIsKenya(string $ip): bool
    {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP) || self::isPrivateIp($ip)) {
            return false; // local/dev/unknown — default to USD rather than guess
        }
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
            $body = @file_get_contents('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,countryCode', false, $ctx);
            if (!$body) return false;
            $data = json_decode($body, true);
            return is_array($data) && ($data['status'] ?? '') === 'success' && ($data['countryCode'] ?? '') === 'KE';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function isPrivateIp(string $ip): bool
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    public static function symbol(string $currency): string
    {
        return strtoupper($currency) === 'KES' ? 'KSh' : '$';
    }

    /** Format an amount for display, e.g. format(1250, 'KES') -> "KSh 1,250.00". */
    public static function format(float $amount, ?string $currency = null): string
    {
        $currency = $currency ?: self::current();
        return self::symbol($currency) . ' ' . number_format($amount, 2);
    }
}
