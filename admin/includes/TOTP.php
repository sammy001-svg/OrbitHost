<?php
/**
 * Orbit Cloud — TOTP (RFC 6238) for admin two-factor authentication.
 *
 * Hand-rolled, matching this codebase's no-external-dependency pattern
 * (same reasoning as Mailer.php's native SMTP client): standard
 * HMAC-SHA1-based HOTP (RFC 4226) stepped every 30 seconds, 6 digits —
 * identical algorithm to Google Authenticator, Authy, 1Password, etc.,
 * so any of them can generate valid codes from a secret set up here.
 *
 * No QR code generation (that needs a full 2D-matrix encoder, well
 * beyond what's reasonable to hand-roll) — setup shows the secret as
 * text for manual entry, which every authenticator app supports
 * ("Enter a setup key" / "Can't scan?").
 */
final class TOTP
{
    private const DIGITS    = 6;
    private const TIME_STEP = 30;

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /** otpauth:// URI — paste-able into most authenticator apps as an alternative to manual entry. */
    public static function otpauthUri(string $secret, string $accountLabel, string $issuer = 'Orbit Cloud'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $accountLabel)
             . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
             . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::TIME_STEP;
    }

    /** Verify a submitted code, allowing ±1 step (30s) of clock drift. */
    public static function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if ($code === '' || !ctype_digit($code)) return false;
        foreach ([-1, 0, 1] as $drift) {
            if (hash_equals(self::codeAt($secret, time() + ($drift * self::TIME_STEP)), $code)) return true;
        }
        return false;
    }

    private static function codeAt(string $secret, int $timestamp): string
    {
        $key     = self::base32Decode($secret);
        $counter = intdiv($timestamp, self::TIME_STEP);
        $binary  = pack('N*', 0) . pack('N*', $counter); // 8-byte big-endian counter
        $hash    = hash_hmac('sha1', $binary, $key, true);
        $offset  = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
                   | ((ord($hash[$offset + 1]) & 0xFF) << 16)
                   | ((ord($hash[$offset + 2]) & 0xFF) << 8)
                   | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string) ($truncated % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($data) as $char) $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32  = strtoupper(rtrim($b32, '='));
        $bits = '';
        foreach (str_split($b32) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) continue;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $out .= chr(bindec($byte));
        }
        return $out;
    }

    /** One-time recovery codes for when the authenticator device is unavailable. */
    public static function generateBackupCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = substr(bin2hex(random_bytes(5)), 0, 10);
        }
        return $codes;
    }
}
