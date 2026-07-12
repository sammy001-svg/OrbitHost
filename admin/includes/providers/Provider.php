<?php
/**
 * OrbitHost — Provider factory & config accessor
 *
 * Bridges the registry (metadata) with saved config (integration_settings)
 * and the concrete category clients. Everything else in the app goes through
 * here instead of newing up a specific vendor client.
 *
 *   Provider::panel('whm')        → PanelClient bound to WHM config
 *   Provider::registrar('enom')   → DomainClient bound to Enom config
 *   Provider::payment('stripe')   → PaymentClient bound to Stripe config
 */
require_once __DIR__ . '/ProviderRegistry.php';
require_once __DIR__ . '/../db.php';

final class Provider
{
    /** Saved config (with registry defaults applied) for a provider. */
    public static function config(string $key): array
    {
        static $cache = [];
        if (isset($cache[$key])) return $cache[$key];

        $stmt = db()->prepare('SELECT settings FROM integration_settings WHERE provider = ?');
        $stmt->execute([$key]);
        $json  = $stmt->fetchColumn();
        $saved = $json ? (json_decode($json, true) ?: []) : [];

        return $cache[$key] = ProviderRegistry::withDefaults($key, $saved);
    }

    public static function isActive(string $key): bool
    {
        $stmt = db()->prepare('SELECT is_active FROM integration_settings WHERE provider = ?');
        $stmt->execute([$key]);
        return (bool) $stmt->fetchColumn();
    }

    /** True when every required field in the registry schema has a value. */
    public static function isConfigured(string $key): bool
    {
        $def = ProviderRegistry::get($key);
        if (!$def) return false;
        $cfg = self::config($key);
        foreach ($def['fields'] as $f) {
            if (!empty($f['required']) && empty($cfg[$f['key']])) {
                return false;
            }
        }
        return true;
    }

    // ── Category factories ────────────────────────────────────
    public static function panel(string $key): PanelClient
    {
        self::assertCategory($key, 'panel');
        require_once __DIR__ . '/PanelClient.php';
        return new PanelClient($key, self::config($key));
    }

    public static function registrar(string $key): DomainClient
    {
        self::assertCategory($key, 'registrar');
        require_once __DIR__ . '/../DomainClient.php';
        return new DomainClient($key, self::config($key));
    }

    public static function payment(string $key): PaymentClient
    {
        self::assertCategory($key, 'payment');
        require_once __DIR__ . '/PaymentClient.php';
        return new PaymentClient($key, self::config($key));
    }

    /** The active provider key for a category, or null. First active wins. */
    public static function activeFor(string $category): ?string
    {
        foreach (ProviderRegistry::byCategory($category) as $key => $_) {
            if (self::isActive($key) && self::isConfigured($key)) {
                return $key;
            }
        }
        return null;
    }

    private static function assertCategory(string $key, string $category): void
    {
        $def = ProviderRegistry::get($key);
        if (!$def || $def['category'] !== $category) {
            throw new RuntimeException("Provider '$key' is not a valid $category provider.");
        }
    }
}
