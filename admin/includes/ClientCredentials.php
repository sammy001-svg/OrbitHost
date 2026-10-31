<?php
/**
 * Orbit Cloud — admin-issued client portal logins.
 *
 * An admin can hand a client a working login without the invite-link
 * round trip:
 *
 *   - password only  → the client signs in with their email address
 *   - username + pwd → the client signs in with a username instead
 *                      (email still works too)
 *
 * The credentials are emailed to the client's address on file. Generated
 * passwords are flagged must_change_password, so the portal makes the
 * client choose their own on first sign-in — a password that has sat in
 * an inbox is not one to keep.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

final class ClientCredentials
{
    /** Usernames: lowercase, 3–30 chars, letters/digits/._- , no '@' (so they never look like an email). */
    public const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{2,29}$/';

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            if (!db()->query("SHOW COLUMNS FROM clients LIKE 'portal_username'")->fetch()) {
                db()->exec('ALTER TABLE clients ADD COLUMN portal_username VARCHAR(30) DEFAULT NULL, ADD UNIQUE KEY uq_clients_portal_username (portal_username)');
            }
        } catch (\Throwable $e) { /* no ALTER privilege — email login keeps working */ }
        try {
            if (!db()->query("SHOW COLUMNS FROM clients LIKE 'must_change_password'")->fetch()) {
                db()->exec('ALTER TABLE clients ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0');
            }
        } catch (\Throwable $e) {}
    }

    /**
     * A 14-character password that always passes password_policy_errors():
     * upper, lower, digit and symbol, no look-alike characters (0/O, 1/l/I)
     * so it can be read off an email and typed without guesswork.
     */
    public static function generatePassword(int $length = 14): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '!@#$%*?-'];
        $all  = implode('', $sets);
        $chars = [];
        foreach ($sets as $s) $chars[] = $s[random_int(0, strlen($s) - 1)];
        while (count($chars) < $length) $chars[] = $all[random_int(0, strlen($all) - 1)];
        for ($i = count($chars) - 1; $i > 0; $i--) { // Fisher–Yates with a CSPRNG
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    /** A free username built from the client's name, e.g. "jane.doe", "jane.doe2". */
    public static function suggestUsername(array $client): string
    {
        self::ensureSchema();
        $base = strtolower(trim(($client['first_name'] ?? '') . '.' . ($client['last_name'] ?? ''), '.'));
        $base = preg_replace('/[^a-z0-9._-]+/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base);
        $base = trim(substr($base, 0, 24), '._-');
        if (strlen($base) < 3) $base = 'client' . (int) ($client['id'] ?? 0);

        $candidate = $base;
        for ($n = 2; !self::usernameAvailable($candidate, (int) ($client['id'] ?? 0)); $n++) {
            $candidate = $base . $n;
            if ($n > 500) { $candidate = $base . random_int(1000, 99999); break; }
        }
        return $candidate;
    }

    public static function usernameAvailable(string $username, int $except_client_id = 0): bool
    {
        self::ensureSchema();
        $s = db()->prepare('SELECT COUNT(*) FROM clients WHERE (portal_username = ? OR email = ?) AND id <> ?');
        $s->execute([$username, $username, $except_client_id]);
        return (int) $s->fetchColumn() === 0;
    }

    /**
     * Set a client's portal login and email it to them.
     *
     * @param string|null $username  null = email login only (any existing username is kept)
     * @param string|null $password  null = generate one
     * @return array{ok:bool, message:string, username?:?string, password?:string, email_sent?:bool, email_error?:?string}
     */
    public static function issue(int $client_id, ?string $username, ?string $password, bool $force_change, int $admin_id): array
    {
        self::ensureSchema();
        require_once __DIR__ . '/Notifier.php';

        $s = db()->prepare('SELECT * FROM clients WHERE id = ?');
        $s->execute([$client_id]);
        $client = $s->fetch();
        if (!$client) return ['ok' => false, 'message' => 'Client not found.'];
        if (!filter_var($client['email'], FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'This client has no valid email address to send the login to.'];
        }

        if ($username !== null) {
            $username = strtolower(trim($username));
            if (!preg_match(self::USERNAME_PATTERN, $username)) {
                return ['ok' => false, 'message' => 'Username must be 3–30 characters: lowercase letters, numbers, dot, dash or underscore, starting with a letter or number.'];
            }
            if (!self::usernameAvailable($username, $client_id)) {
                return ['ok' => false, 'message' => 'That username is already taken.'];
            }
        }

        $generated = $password === null || $password === '';
        if ($generated) {
            $password = self::generatePassword();
        } else {
            $policy = password_policy_errors($password, [$client['email'], $client['first_name'], $client['last_name'], (string) $username]);
            if ($policy) return ['ok' => false, 'message' => implode(' ', $policy)];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        try {
            if ($username !== null) {
                db()->prepare('UPDATE clients SET portal_password = ?, portal_username = ?, must_change_password = ? WHERE id = ?')
                    ->execute([$hash, $username, $force_change ? 1 : 0, $client_id]);
            } else {
                db()->prepare('UPDATE clients SET portal_password = ?, must_change_password = ? WHERE id = ?')
                    ->execute([$hash, $force_change ? 1 : 0, $client_id]);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not save the login: ' . $e->getMessage()];
        }

        // A pending invite link would let someone set a different password
        // behind this one — retire it.
        try { db()->prepare('DELETE FROM portal_invites WHERE client_id = ? AND accepted_at IS NULL')->execute([$client_id]); } catch (\Throwable $e) {}

        $login_as = $username ?? ($client['portal_username'] ?? null);
        $rows = '<tr><td style="padding:6px 0;color:#64748b">Email</td><td style="padding:6px 0;text-align:right;font-weight:700">' . htmlspecialchars($client['email']) . '</td></tr>';
        if ($login_as) {
            $rows .= '<tr><td style="padding:6px 0;color:#64748b">Username</td><td style="padding:6px 0;text-align:right;font-weight:700;font-family:monospace">' . htmlspecialchars($login_as) . '</td></tr>';
        }
        $rows .= '<tr><td style="padding:6px 0;color:#64748b">Password</td><td style="padding:6px 0;text-align:right;font-weight:700;font-family:monospace">' . htmlspecialchars($password) . '</td></tr>';

        $portal = portal_base_url();
        Notifier::send('portal_credentials', $client_id, [
            'client_name'  => htmlspecialchars(trim($client['first_name'] . ' ' . $client['last_name'])),
            'login_hint'   => $login_as ? 'your username <strong>' . htmlspecialchars($login_as) . '</strong> or your email address' : 'your email address',
            'credential_rows' => $rows,
            'change_note'  => $force_change ? '<p>For your security you\'ll be asked to choose a new password the first time you sign in.</p>' : '',
            'login_url'    => $portal . '/login.php',
            'email'        => $client['email'],
            'link'         => $portal . '/profile.php',
        ]);

        // Notifier records the email outcome on the notification row it just wrote.
        $sent = null; $err = null;
        try {
            $q = db()->prepare("SELECT email_sent, email_error FROM notifications WHERE audience = 'client' AND recipient_id = ? AND type = 'portal_credentials' ORDER BY id DESC LIMIT 1");
            $q->execute([$client_id]);
            if ($n = $q->fetch()) { $sent = $n['email_sent'] === null ? null : (bool) $n['email_sent']; $err = $n['email_error']; }
        } catch (\Throwable $e) {}

        log_activity('client_credentials_issued', 'client', $client_id,
            'Portal login ' . ($generated ? 'generated' : 'set') . ' for ' . $client['email']
            . ($username !== null ? ' (username ' . $username . ')' : ' (email login)')
            . ($sent === false ? ' — email FAILED: ' . $err : ' — emailed to client'));

        return [
            'ok'          => true,
            'message'     => $sent === false ? 'Login saved, but the email could not be sent: ' . $err : 'Login saved and emailed to ' . $client['email'] . '.',
            'username'    => $login_as,
            'password'    => $password,
            'email_sent'  => $sent !== false,
            'email_error' => $err,
        ];
    }
}
