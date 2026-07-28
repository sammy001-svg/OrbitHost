<?php
/**
 * Orbit Cloud — hosting order cart.
 *
 * Holds the in-progress order across the four checkout steps in the
 * session, so the whole funnel works before the visitor has an account
 * (they sign in or register at step 4, exactly like the domain cart).
 *
 * Every price shown anywhere in the funnel comes from summary() — one
 * place doing the maths means the figure on step 2 is by construction the
 * figure that ends up on the invoice.
 *
 * Session shape (key: hosting_order):
 *   plan_id       int    services.id of the chosen package
 *   domain_mode   register|transfer|existing
 *   domain_name   string
 *   domain_years  int    registration/transfer term
 *   id_protection bool   WHOIS privacy on the domain
 *   addons        int[]  service_addons.id
 */
require_once dirname(__DIR__, 2) . '/admin/includes/db.php';
require_once dirname(__DIR__, 2) . '/admin/includes/Currency.php';
require_once dirname(__DIR__, 2) . '/admin/includes/SiteSettings.php';
require_once dirname(__DIR__, 2) . '/admin/includes/ServiceAddon.php';

final class OrderCart
{
    private const KEY = 'hosting_order';

    public static function all(): array
    {
        if (!isset($_SESSION[self::KEY]) || !is_array($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = [
                'plan_id' => 0, 'domain_mode' => '', 'domain_name' => '',
                'domain_years' => 1, 'id_protection' => false, 'addons' => [],
            ];
        }
        return $_SESSION[self::KEY];
    }

    public static function set(string $key, $value): void
    {
        self::all();
        $_SESSION[self::KEY][$key] = $value;
    }

    public static function get(string $key, $default = null)
    {
        return self::all()[$key] ?? $default;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::KEY]);
    }

    // ── Plan ────────────────────────────────────────────────────────────

    /** Resolve a plan by id, or by the website's category-name slug. */
    public static function findPlan(string $idOrSlug): ?array
    {
        $idOrSlug = trim($idOrSlug);
        if ($idOrSlug === '') return null;

        if (ctype_digit($idOrSlug)) {
            $stmt = db()->prepare('SELECT * FROM services WHERE id = ? AND is_active = 1');
            $stmt->execute([(int) $idOrSlug]);
            if ($row = $stmt->fetch()) return $row;
        }

        // Slug form (shared-business) — matches api/plans.php's plan_slug().
        try {
            $rows = db()->query('SELECT * FROM services WHERE is_active = 1')->fetchAll();
        } catch (\Throwable $e) {
            return null;
        }
        foreach ($rows as $r) {
            $slug = strtolower(trim($r['category'] . '-' . $r['name']));
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
            if ($slug === strtolower($idOrSlug)) return $r;
        }
        return null;
    }

    public static function plan(): ?array
    {
        $id = (int) self::get('plan_id', 0);
        if (!$id) return null;
        $stmt = db()->prepare('SELECT * FROM services WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The billing cycles this package can be bought on.
     *
     * A plan in the catalogue has exactly one billing cycle, so "Starter
     * monthly" and "Starter annual" are two rows. The cycle chooser on
     * step 2 therefore offers the sibling rows sharing this plan's name and
     * category — switching cycle switches which plan is being ordered. A
     * package with no siblings simply has one option and no choice to make.
     *
     * @return array<string,array> keyed by billing_cycle
     */
    public static function cycleOptions(array $plan): array
    {
        $stmt = db()->prepare('SELECT * FROM services WHERE is_active = 1 AND category = ? AND name = ? ORDER BY FIELD(billing_cycle,"monthly","annual","one_time")');
        $stmt->execute([$plan['category'], $plan['name']]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['billing_cycle']] = $r;
        }
        if (!$out) $out[$plan['billing_cycle']] = $plan;
        return $out;
    }

    // ── Add-ons ─────────────────────────────────────────────────────────

    public static function addonIds(): array
    {
        return array_values(array_filter(array_map('intval', (array) self::get('addons', []))));
    }

    public static function setAddons(array $ids, string $category): void
    {
        // Only ids actually offered for this category can be stored, so a
        // hand-edited form can't slip a hidden add-on onto the invoice.
        $allowed = array_map(fn($a) => (int) $a['id'], ServiceAddon::forCategory($category));
        self::set('addons', array_values(array_intersect(array_map('intval', $ids), $allowed)));
    }

    // ── Domain ──────────────────────────────────────────────────────────

    public static function domainYears(): int
    {
        return max(1, min(10, (int) self::get('domain_years', 1)));
    }

    /** TLD of the chosen domain, e.g. "co.ke". */
    public static function domainTld(): string
    {
        $parts = explode('.', (string) self::get('domain_name', ''), 2);
        return $parts[1] ?? '';
    }

    /** Pricing row for the chosen domain's TLD, or null if we don't sell it. */
    public static function tldRow(): ?array
    {
        $tld = self::domainTld();
        if ($tld === '') return null;
        try {
            $stmt = db()->prepare('SELECT * FROM domain_tlds WHERE tld = ? AND is_active = 1');
            $stmt->execute([$tld]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** ID Protection is only meaningful on a domain we're registering/transferring. */
    public static function idProtectionAvailable(): bool
    {
        return in_array(self::get('domain_mode'), ['register', 'transfer'], true);
    }

    // ── Pricing ─────────────────────────────────────────────────────────

    /**
     * The complete order: line items, subtotal, VAT and total, in the
     * checkout currency. Every step renders from this, and placing the
     * order writes these exact lines onto the invoice.
     *
     * @return array{lines:array,subtotal:float,vat:float,vat_rate:float,total:float,currency:string}
     */
    public static function summary(string $currency): array
    {
        $lines = [];
        $plan  = self::plan();

        if ($plan) {
            $amt   = Currency::planAmount($plan, $currency);
            $cycle = str_replace('_', ' ', (string) $plan['billing_cycle']);
            $lines[] = [
                'type'  => 'plan',
                'label' => $plan['name'] . ' — ' . ucfirst($plan['category']) . ' hosting (' . $cycle . ')',
                'amount' => (float) $amt['price'],
            ];
            if ((float) $amt['setup_fee'] > 0) {
                $lines[] = ['type' => 'setup', 'label' => 'One-time setup fee', 'amount' => (float) $amt['setup_fee']];
            }
        }

        // Domain
        $mode  = (string) self::get('domain_mode', '');
        $name  = (string) self::get('domain_name', '');
        $years = self::domainYears();
        $tld   = self::tldRow();

        if ($name !== '' && $mode === 'register' && $tld) {
            $unit = (float) ($currency === 'KES' ? ($tld['register_price_kes'] ?? 0) : ($tld['register_price_usd'] ?? 0));
            $lines[] = [
                'type' => 'domain',
                'label' => 'Domain registration — ' . $name . ' (' . $years . ' ' . ($years === 1 ? 'year' : 'years') . ')',
                'amount' => $unit * $years,
            ];
        } elseif ($name !== '' && $mode === 'transfer' && $tld) {
            $unit = (float) ($currency === 'KES' ? ($tld['transfer_price_kes'] ?? 0) : ($tld['transfer_price_usd'] ?? 0));
            $lines[] = ['type' => 'domain', 'label' => 'Domain transfer — ' . $name . ' (adds 1 year)', 'amount' => $unit];
        } elseif ($name !== '' && $mode === 'existing') {
            $lines[] = ['type' => 'domain', 'label' => 'Using your existing domain — ' . $name, 'amount' => 0.0];
        }

        // ID Protection
        if (self::get('id_protection') && self::idProtectionAvailable() && $name !== '') {
            $billing = SiteSettings::billing();
            $unit    = (float) ($billing['id_protection'][$currency] ?? 0);
            $qty     = $mode === 'register' ? $years : 1;
            if ($unit > 0) {
                $lines[] = [
                    'type' => 'id_protection',
                    'label' => 'ID Protection — ' . $name . ' (' . $qty . ' ' . ($qty === 1 ? 'year' : 'years') . ')',
                    'amount' => $unit * $qty,
                ];
            }
        }

        // Add-ons
        foreach (ServiceAddon::byIds(self::addonIds()) as $a) {
            $lines[] = [
                'type' => 'addon',
                'addon_id' => (int) $a['id'],
                'label' => $a['name'] . ServiceAddon::cycleSuffix($a['billing_cycle']),
                'amount' => ServiceAddon::price($a, $currency),
            ];
        }

        $subtotal = 0.0;
        foreach ($lines as $l) $subtotal += (float) $l['amount'];
        $subtotal = round($subtotal, 2);

        $billing  = SiteSettings::billing();
        $vat_rate = $billing['vat_enabled'] ? (float) $billing['vat_rate'] : 0.0;
        $vat      = round($subtotal * $vat_rate / 100, 2);

        return [
            'lines'    => $lines,
            'subtotal' => $subtotal,
            'vat'      => $vat,
            'vat_rate' => $vat_rate,
            'total'    => round($subtotal + $vat, 2),
            'currency' => $currency,
        ];
    }

    /**
     * Which step the cart is actually ready for — every page calls this so
     * a deep link or a back-button can't land someone on review with half
     * an order behind them.
     */
    public static function readyFor(string $step): bool
    {
        $has_plan   = self::plan() !== null;
        $has_domain = trim((string) self::get('domain_name', '')) !== '' && self::get('domain_mode') !== '';
        switch ($step) {
            case 'domain':     return $has_plan;
            case 'configure':  return $has_plan && $has_domain;
            case 'domain-config': return $has_plan && $has_domain;
            case 'review':     return $has_plan && $has_domain;
        }
        return false;
    }
}
