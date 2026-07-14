<?php

function flash_set(string $type, string $message): void
{
    auth_start();
    $_SESSION['flash'] = compact('type', 'message');
}

function flash_get(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function format_money(float $amount): string
{
    return CURRENCY . ' ' . number_format($amount, 2);
}

function format_date(?string $date): string
{
    if (!$date || $date === '0000-00-00') return '—';
    return date('M d, Y', strtotime($date));
}

function format_datetime(?string $dt): string
{
    if (!$dt || $dt === '0000-00-00 00:00:00') return '—';
    return date('M d, Y H:i', strtotime($dt));
}

function time_ago(string $dt): string
{
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return format_date($dt);
}

function badge(string $status): string
{
    $map = [
        'active'    => 'badge-success',
        'paid'      => 'badge-success',
        'answered'  => 'badge-success',
        'closed'    => 'badge-secondary',
        'cancelled' => 'badge-secondary',
        'expired'   => 'badge-secondary',
        'draft'     => 'badge-secondary',
        'pending'   => 'badge-warning',
        'overdue'   => 'badge-danger',
        'open'      => 'badge-primary',
        'sent'      => 'badge-primary',
        'suspended' => 'badge-danger',
        'high'      => 'badge-danger',
        'urgent'    => 'badge-urgent',
        'medium'    => 'badge-warning',
        'low'       => 'badge-secondary',
        'one_time'  => 'badge-secondary',
        'monthly'   => 'badge-primary',
        'annual'    => 'badge-success',
    ];
    $class = $map[strtolower($status)] ?? 'badge-secondary';
    $label = str_replace('_', ' ', ucfirst($status));
    return '<span class="badge ' . $class . '">' . $label . '</span>';
}

function paginate(int $total, int $page, int $per_page, string $base_url): string
{
    if ($total <= $per_page) return '';
    $pages = (int) ceil($total / $per_page);
    $sep   = strpos($base_url, '?') !== false ? '&' : '?';
    $html  = '<div class="pagination">';
    if ($page > 1) {
        $html .= '<a href="' . $base_url . $sep . 'page=' . ($page - 1) . '" class="page-btn">‹ Prev</a>';
    }
    $start = max(1, $page - 2);
    $end   = min($pages, $page + 2);
    if ($start > 1)      $html .= '<a href="' . $base_url . $sep . 'page=1" class="page-btn">1</a>' . ($start > 2 ? '<span class="page-btn" style="border:none">…</span>' : '');
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $page ? ' active' : '';
        $html  .= '<a href="' . $base_url . $sep . 'page=' . $i . '" class="page-btn' . $active . '">' . $i . '</a>';
    }
    if ($end < $pages)   $html .= ($end < $pages - 1 ? '<span class="page-btn" style="border:none">…</span>' : '') . '<a href="' . $base_url . $sep . 'page=' . $pages . '" class="page-btn">' . $pages . '</a>';
    if ($page < $pages)  $html .= '<a href="' . $base_url . $sep . 'page=' . ($page + 1) . '" class="page-btn">Next ›</a>';
    $html .= '</div>';
    return $html;
}

function generate_ticket_number(): string
{
    return 'TKT-' . date('Y') . strtoupper(bin2hex(random_bytes(3)));
}

function generate_invoice_number(): string
{
    $stmt = db()->query('SELECT COUNT(*) FROM invoices');
    $n = (int) $stmt->fetchColumn() + 1;
    return 'INV-' . date('Y') . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}

function log_activity(string $action, string $entity_type = '', int $entity_id = 0, string $description = ''): void
{
    try {
        $admin = current_admin();
        db()->prepare('INSERT INTO activity_log (admin_id, action, entity_type, entity_id, description, ip_address) VALUES (?,?,?,?,?,?)')
            ->execute([$admin['id'], $action, $entity_type ?: null, $entity_id ?: null, $description ?: null, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {
        // Non-fatal
    }
}

function get_countries(): array
{
    return [
        'Kenya','Uganda','Tanzania','Rwanda','Ethiopia','Nigeria','Ghana','South Africa',
        'Egypt','Morocco','USA','United Kingdom','Canada','Australia','Germany','France',
        'India','China','UAE','Saudi Arabia','Other',
    ];
}

function get_payment_methods(): array
{
    return ['M-Pesa','Airtel Money','Credit Card','PayPal','Bank Transfer','Cheque','Crypto','Other'];
}

/**
 * The client portal's base URL, computed from admin context (admin has
 * no PORTAL_URL constant of its own — that's only defined inside
 * portal/includes/config.php). Used when admin-side code needs to link
 * a client notification/email back to their portal.
 *
 * Derived from APP_URL (site root + /admin) rather than $_SERVER
 * directly, so it degrades gracefully in a CLI cron context too — as
 * long as APP_URL is set explicitly in .env (there is no HTTP request
 * to auto-detect it from when cron runs "php reminders.php" on the
 * command line).
 */
function portal_base_url(): string
{
    return preg_replace('#/admin/?$#i', '', rtrim(APP_URL, '/')) . '/portal';
}

/** Auto-migrate the email-verification columns onto clients (idempotent, once per request). */
function ensure_client_verify_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $col = db()->query("SHOW COLUMNS FROM clients LIKE 'verify_token'")->fetch();
        if (!$col) {
            db()->exec("ALTER TABLE clients
                ADD COLUMN verify_token   VARCHAR(64) DEFAULT NULL,
                ADD COLUMN verify_expires DATETIME    DEFAULT NULL");
        }
    } catch (\Throwable $e) {
        // no ALTER privilege — verification simply won't be available until schema is added manually
    }
}

/**
 * Absolute webhook callback URL for a specific payment. Embedding the
 * payment id directly (rather than trying to parse it back out of
 * whatever a gateway's callback payload happens to contain) means the
 * webhook receiver never has to guess which payment fired it — it just
 * re-verifies that exact payment with the gateway via
 * Automation::settlePayment(), the same trusted path the client's own
 * return page and the reconciliation cron both use. Currently only
 * consumed by Kopo Kopo (the only gateway that takes a per-request
 * callback URL); harmless no-op value for gateways that ignore it.
 */
function payment_webhook_url(int $payment_id, string $gateway = 'kopokopo'): string
{
    $site_root = preg_replace('#/admin/?$#i', '', rtrim(APP_URL, '/'));
    return $site_root . '/api/webhooks/' . $gateway . '.php?pay=' . $payment_id;
}
