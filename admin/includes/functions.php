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
    return ['M-Pesa','Airtel Money','Credit Card','PayPal','Bank Transfer','Crypto','Other'];
}
