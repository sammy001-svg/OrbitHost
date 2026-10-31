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

    /** Empty the whole cart: the hosting order AND the domain items. */
    public static function clear(): void
    {
        unset($_SESSION[self::KEY], $_SESSION[self::DOMAINS]);
    }

    // ── Domain items ────────────────────────────────────────────────────
    //
    // Domains bought on their own (the website search, the portal domain
    // search) — as opposed to the one domain a hosting package is set up
    // on, which lives in hosting_order above. Same session key the old
    // domain cart used, so nothing in flight is lost; entries just gained
    // more fields:
    //   [domain => [tld, mode register|transfer, years, epp, id_protection]]

    private const DOMAINS = 'cart_domains';

    public static function domains(): array
    {
        $out = [];
        foreach ((array) ($_SESSION[self::DOMAINS] ?? []) as $d => $it) {
            if (!is_array($it)) continue;
            $out[$d] = [
                'tld'           => (string) ($it['tld'] ?? (explode('.', $d, 2)[1] ?? '')),
                'mode'          => ($it['mode'] ?? 'register') === 'transfer' ? 'transfer' : 'register',
                'years'         => max(1, min(10, (int) ($it['years'] ?? 1))),
                'epp'           => (string) ($it['epp'] ?? ''),
                'id_protection' => !empty($it['id_protection']),
            ];
        }
        return $out;
    }

    public static function putDomain(string $domain, array $item): void
    {
        $cur = self::domains()[$domain] ?? ['mode' => 'register', 'years' => 1, 'epp' => '', 'id_protection' => false];
        $_SESSION[self::DOMAINS][$domain] = array_merge($cur, $item, ['tld' => explode('.', $domain, 2)[1] ?? '']);
    }

    public static function removeDomain(string $domain): void
    {
        unset($_SESSION[self::DOMAINS][$domain]);
        // A hosting package set up on this cart domain no longer has one.
        if (self::get('domain_mode') === 'cart' && self::get('domain_name') === $domain) {
            self::set('domain_mode', '');
            self::set('domain_name', '');
        }
    }

    /**
     * Is this domain definitely already registered?
     * true = taken. false = free. null = couldn't tell (no registrar, API
     * down, provider without an availability API) — the sale goes ahead
     * and the team confirms before registering, rather than our outage
     * blocking an order.
     */
    public static function domainTaken(string $domain): ?bool
    {
        static $memo = [];
        if (array_key_exists($domain, $memo)) return $memo[$domain];
        try {
            require_once dirname(__DIR__, 2) . '/admin/includes/providers/Provider.php';
            require_once dirname(__DIR__, 2) . '/admin/includes/DomainClient.php';
            $key = Provider::activeFor('registrar');
            if (!$key) return $memo[$domain] = null;
            $r = Provider::registrar($key)->check($domain);
            $a = $r['available'] ?? null;
            return $memo[$domain] = ($a === null ? null : !$a);
        } catch (\Throwable $e) {
            return $memo[$domain] = null;
        }
    }

    /** Normalise user input to a bare domain name, or '' if it isn't one. */
    public static function cleanDomain(string $raw): string
    {
        $d = strtolower(trim($raw));
        $d = preg_replace('#^https?://#', '', $d);
        $d = preg_replace('#^www\.#', '', $d);
        $d = preg_replace('/[^a-z0-9.-]/', '', rtrim($d, '/'));
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.[a-z0-9.-]{2,}$/', $d) ? $d : '';
    }

    /** Number of things in the cart (hosting counts once), for badges. */
    public static function count(): int
    {
        return count(self::domains()) + (self::plan() ? 1 : 0);
    }

    /** Pricing row for any TLD we sell, or null. */
    public static function tldPricing(string $tld): ?array
    {
        static $cache = [];
        if (array_key_exists($tld, $cache)) return $cache[$tld];
        try {
            $s = db()->prepare('SELECT * FROM domain_tlds WHERE tld = ? AND is_active = 1');
            $s->execute([$tld]);
            return $cache[$tld] = ($s->fetch() ?: null);
        } catch (\Throwable $e) {
            return $cache[$tld] = null;
        }
    }

    /** Has the hosting part of the cart got everything it needs? */
    public static function hostingComplete(): bool
    {
        return self::plan() !== null
            && trim((string) self::get('domain_name', '')) !== '' && self::get('domain_mode') !== '';
    }

    /** Is there anything at all that can be checked out? */
    public static function checkoutReady(): bool
    {
        // A started-but-unfinished hosting order blocks checkout: it would
        // otherwise be silently dropped.
        if (self::plan() && !self::hostingComplete()) return false;
        return self::hostingComplete() || self::domains() !== [];
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

        // Slug form (shared-business) — must match api/plans.php's plan_slug(),
        // which is what the website's CTAs are built from.
        // Cheapest first: a package published on both monthly and annual has
        // two rows sharing one slug, and the entry point should land on the
        // lower commitment — step 2's cycle chooser offers the sibling.
        try {
            $rows = db()->query('SELECT * FROM services WHERE is_active = 1 ORDER BY price')->fetchAll();
        } catch (\Throwable $e) {
            return null;
        }
        $want = strtolower($idOrSlug);
        foreach ($rows as $r) {
            if (self::planSlug((string) $r['category'], (string) $r['name']) === $want) return $r;
        }
        return null;
    }

    /** Keep in lockstep with plan_slug() in api/plans.php. */
    public static function planSlug(string $category, string $name): string
    {
        $cat  = strtolower(trim($category));
        $norm = strtolower(trim($name));
        $slug = str_starts_with($norm, $cat . ' ') ? $norm : $cat . '-' . $norm;
        return trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
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
        } elseif ($name !== '' && $mode === 'cart' && $plan) {
            // Billed by its own domain line below — this just says which
            // domain the hosting goes on.
            $lines[] = ['type' => 'domain', 'label' => 'Hosting domain — ' . $name . ' (in this order)', 'amount' => 0.0];
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

        // Domain items (bought on their own, or the one the hosting uses
        // in 'cart' mode). The hosting domain is skipped if it's somehow
        // also here as register/transfer, so it is never billed twice.
        $billing_idp = (float) (SiteSettings::billing()['id_protection'][$currency] ?? 0);
        foreach (self::domains() as $d => $it) {
            if ($plan && $d === $name && in_array($mode, ['register', 'transfer'], true)) continue;
            $row = self::tldPricing($it['tld']);
            if (!$row) continue; // TLD withdrawn — cart page flags it
            $col  = $it['mode'] === 'transfer' ? 'transfer' : 'register';
            $unit = (float) ($currency === 'KES' ? ($row[$col . '_price_kes'] ?? 0) : ($row[$col . '_price_usd'] ?? 0));
            $yrs  = $it['mode'] === 'transfer' ? 1 : $it['years'];
            $lines[] = [
                'type' => 'cart_domain', 'domain' => $d, 'mode' => $it['mode'], 'years' => $yrs, 'epp' => $it['epp'],
                'label' => ($it['mode'] === 'transfer' ? 'Domain transfer — ' . $d . ' (adds 1 year)'
                                                        : 'Domain registration — ' . $d . ' (' . $yrs . ' ' . ($yrs === 1 ? 'year' : 'years') . ')'),
                'amount' => $unit * $yrs,
            ];
            if ($it['id_protection'] && $billing_idp > 0) {
                $lines[] = ['type' => 'id_protection', 'domain' => $d,
                            'label' => 'ID Protection — ' . $d . ' (' . $yrs . ' ' . ($yrs === 1 ? 'year' : 'years') . ')',
                            'amount' => $billing_idp * $yrs];
            }
        }

        // Add-ons
        foreach (ServiceAddon::byIds(self::addonIds()) as $a) {
            $lines[] = [
                'type' => 'addon',
                'addon_id' => (int) $a['id'],
                // label is for display; name/cycle are the raw values
                // _place.php snapshots onto the client's subscription.
                'name'  => (string) $a['name'],
                'cycle' => (string) $a['billing_cycle'],
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
            case 'review':     return self::checkoutReady();
        }
        return false;
    }
}
