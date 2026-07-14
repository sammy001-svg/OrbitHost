<?php
/**
 * Orbit Cloud — login rate-limiting / lockout.
 *
 * Shared by both the admin panel and client portal login flows (and the
 * admin 2FA step). Two independent limits, since either alone has a gap:
 *   - per-account: N failed attempts against the SAME email locks that
 *     account out for a window, regardless of source IP (stops anyone
 *     brute-forcing one target from many IPs/proxies).
 *   - per-IP: M failed attempts from the SAME IP across ANY email
 *     throttles that IP (stops credential-stuffing many accounts from
 *     one source, which per-account limits alone wouldn't catch).
 * Both are sliding windows (count of failures in the last N minutes),
 * so continuing to guess keeps the lockout extended — a correct
 * password submitted while locked out is still rejected, matching how
 * every mainstream login-lockout implementation behaves.
 */
final class LoginGuard
{
    private const MAX_ACCOUNT_ATTEMPTS = 5;
    private const MAX_IP_ATTEMPTS      = 20;
    private const WINDOW_MINUTES       = 15;

    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS login_attempts (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                scope      VARCHAR(20)  NOT NULL,
                identifier VARCHAR(150) NOT NULL,
                ip_address VARCHAR(45)  DEFAULT NULL,
                success    TINYINT(1)   NOT NULL DEFAULT 0,
                created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_la_account (scope, identifier, created_at),
                INDEX idx_la_ip      (scope, ip_address, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            // no CREATE privilege — login proceeds unthrottled rather than hard-failing
        }
    }

    /** Call BEFORE verifying a password. Returns a user-facing message if blocked, else null. */
    public static function checkBlocked(string $scope, string $identifier): ?string
    {
        self::ensureTable();
        $identifier = strtolower(trim($identifier));
        try {
            $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND identifier = ? AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)');
            $stmt->execute([$scope, $identifier]);
            if ((int) $stmt->fetchColumn() >= self::MAX_ACCOUNT_ATTEMPTS) {
                return 'Too many failed attempts for this account. Please try again in ' . self::WINDOW_MINUTES . ' minutes, or reset your password.';
            }

            $ip = self::clientIp();
            $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND ip_address = ? AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)');
            $stmt->execute([$scope, $ip]);
            if ((int) $stmt->fetchColumn() >= self::MAX_IP_ATTEMPTS) {
                return 'Too many failed sign-in attempts from your network. Please try again in ' . self::WINDOW_MINUTES . ' minutes.';
            }
        } catch (\Throwable $e) {
            return null; // table unavailable — fail open rather than lock everyone out
        }
        return null;
    }

    /** Call AFTER every login attempt (success or failure) to feed the limiter. */
    public static function record(string $scope, string $identifier, bool $success): void
    {
        self::ensureTable();
        try {
            db()->prepare('INSERT INTO login_attempts (scope, identifier, ip_address, success) VALUES (?,?,?,?)')
                ->execute([$scope, strtolower(trim($identifier)), self::clientIp(), $success ? 1 : 0]);
            // Opportunistic housekeeping — bounds table growth without needing its own cron.
            if (random_int(1, 50) === 1) {
                db()->exec('DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
            }
        } catch (\Throwable $e) { /* never block a login on logging failure */ }
    }

    private static function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }
}
