<?php
/**
 * OrbitHost — Mailer
 *
 * Native SMTP client (no external dependencies) that actually exercises
 * the saved SMTP configuration, so "Send test email" verifies the real
 * host / port / encryption / credentials. Falls back to PHP mail() when
 * no SMTP host is configured.
 */
require_once __DIR__ . '/providers/Provider.php';

final class Mailer
{
    private array $cfg;

    public function __construct(array $cfg) { $this->cfg = $cfg; }

    public static function fromConfig(): self
    {
        return new self(Provider::config('smtp'));
    }

    public function isSmtp(): bool { return !empty($this->cfg['host']); }

    /** Send a branded test email. */
    public function test(string $to): array
    {
        $when = date('M j, Y H:i');
        $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:520px;margin:auto">'
              . '<div style="background:#0B1E3D;color:#fff;padding:20px 24px;border-radius:12px 12px 0 0">'
              . '<span style="font-size:18px;font-weight:800">Orbit<span style="color:#1A8A45">Host</span></span></div>'
              . '<div style="border:1px solid #e3e8f0;border-top:none;border-radius:0 0 12px 12px;padding:24px">'
              . '<h2 style="margin:0 0 10px;font-size:17px;color:#0B1E3D">✅ Email configuration works</h2>'
              . '<p style="color:#475569;font-size:14px;line-height:1.6">If you are reading this, your OrbitHost admin console '
              . 'successfully delivered an email through <strong>' . htmlspecialchars($this->cfg['host'] ?: 'PHP mail()') . '</strong>.</p>'
              . '<p style="color:#94a3b8;font-size:12px">Sent ' . $when . '</p></div></div>';

        return $this->send($to, 'OrbitHost — SMTP test email', $html);
    }

    /** Send an email. Uses SMTP when a host is configured, else PHP mail(). */
    public function send(string $to, string $subject, string $htmlBody): array
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid recipient address.'];
        }
        return $this->isSmtp() ? $this->smtpSend($to, $subject, $htmlBody) : $this->phpMailSend($to, $subject, $htmlBody);
    }

    // ── PHP mail() fallback ───────────────────────────────────
    private function phpMailSend(string $to, string $subject, string $html): array
    {
        $fromEmail = $this->cfg['from_email'] ?: ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $fromName  = $this->cfg['from_name'] ?: 'OrbitHost';
        $headers   = 'From: ' . $this->encodeName($fromName) . " <{$fromEmail}>\r\n"
                   . "MIME-Version: 1.0\r\n"
                   . "Content-Type: text/html; charset=UTF-8\r\n";
        $ok = @mail($to, $subject, $html, $headers);
        return ['success' => $ok,
                'message'  => $ok ? "Sent to {$to} via PHP mail() (no SMTP host configured)."
                                  : 'PHP mail() failed and no SMTP host is configured. Add SMTP settings above.'];
    }

    // ── Native SMTP ───────────────────────────────────────────
    private function smtpSend(string $to, string $subject, string $html): array
    {
        $host = preg_replace('#^https?://#i', '', trim($this->cfg['host']));
        $port = (int)($this->cfg['port'] ?? 587);
        $enc  = strtolower($this->cfg['encryption'] ?? 'tls');
        $user = $this->cfg['username'] ?? '';
        $pass = $this->cfg['password'] ?? '';
        $fromEmail = $this->cfg['from_email'] ?: $user;
        $fromName  = $this->cfg['from_name'] ?: 'OrbitHost';

        $transport = ($enc === 'ssl') ? 'ssl://' : '';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
        $fp  = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            $msg = "Cannot connect to {$host}:{$port} — {$errstr} ({$errno}).";
            if ($errno === 0) {
                $msg .= " This usually indicates a DNS resolution failure, or your hosting provider is blocking outbound connections on port {$port} via a firewall. " .
                        "On cPanel/shared servers, try setting the SMTP Host to 'localhost' or '127.0.0.1' to bypass outbound SMTP restrictions.";
            }
            return ['success' => false, 'message' => $msg];
        }
        stream_set_timeout($fp, 15);

        $read = function () use ($fp) {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') break; // last line of a (multiline) reply
            }
            return $data;
        };
        $code = fn($r) => (int) substr((string)$r, 0, 3);
        $cmd  = function ($c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };
        $fail = function ($msg) use ($fp) { @fwrite($fp, "QUIT\r\n"); fclose($fp); return ['success' => false, 'message' => $msg]; };

        if ($code($read()) !== 220) return $fail('Server did not greet correctly.');

        $ehlo = $cmd('EHLO orbithost.local');
        if ($code($ehlo) !== 250) { $cmd('HELO orbithost.local'); }

        if ($enc === 'tls') {
            if ($code($cmd('STARTTLS')) !== 220) return $fail('STARTTLS was refused by the server.');
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (!@stream_socket_enable_crypto($fp, true, $crypto)) return $fail('TLS negotiation failed.');
            $cmd('EHLO orbithost.local');
        }

        if ($user !== '') {
            if ($code($cmd('AUTH LOGIN')) !== 334) return $fail('Server did not accept AUTH LOGIN.');
            if ($code($cmd(base64_encode($user))) !== 334) return $fail('Username was rejected.');
            if ($code($cmd(base64_encode($pass))) !== 235) return $fail('Authentication failed — check username / password.');
        }

        if ($code($cmd("MAIL FROM:<{$fromEmail}>")) !== 250) return $fail('Sender address rejected (MAIL FROM).');
        $r = $cmd("RCPT TO:<{$to}>");
        if ($code($r) !== 250 && $code($r) !== 251) return $fail('Recipient rejected (RCPT TO): ' . trim((string)$r));
        if ($code($cmd('DATA')) !== 354) return $fail('Server refused DATA.');

        $headers = implode("\r\n", [
            'From: ' . $this->encodeName($fromName) . " <{$fromEmail}>",
            "To: <{$to}>",
            'Subject: ' . $this->encodeHeader($subject),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@orbithost>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ]);
        $body    = preg_replace('/^\./m', '..', $html); // dot-stuffing
        fwrite($fp, $headers . "\r\n\r\n" . $body . "\r\n.\r\n");
        if ($code($read()) !== 250) return $fail('Server did not accept the message body.');

        $cmd('QUIT');
        fclose($fp);
        return ['success' => true, 'message' => "Test email sent to {$to} via {$host}:{$port}."];
    }

    private function encodeName(string $name): string
    {
        return preg_match('/[^\x20-\x7e]/', $name) ? $this->encodeHeader($name) : $name;
    }
    private function encodeHeader(string $text): string
    {
        return preg_match('/[^\x20-\x7e]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
    }
}
