<?php
// ── Database ──────────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'orbithost_admin');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

// ── Application ───────────────────────────────────────────────
define('APP_NAME', 'OrbitHost Admin');
define('TAX_RATE',  16);    // Default VAT %
define('CURRENCY', 'USD');
define('PER_PAGE',  20);

// ── Session timeout (seconds) ─────────────────────────────────
define('SESSION_TIMEOUT', 7200);

// ── Auto-detect APP_URL (works from /admin/ or any subdirectory)
if (!defined('APP_URL') && !defined('SETUP_MODE')) {
    $__prot  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $__host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $__root  = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $__doc   = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $__rel   = str_replace($__doc, '', $__root);
    define('APP_URL', $__prot . '://' . $__host . $__rel);
    unset($__prot, $__host, $__root, $__doc, $__rel);
} elseif (!defined('APP_URL')) {
    // setup.php context
    $__prot  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $__host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $__root  = rtrim(str_replace('\\', '/', dirname(dirname(__DIR__))), '/');
    $__doc   = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $__rel   = str_replace($__doc, '', $__root) . '/admin';
    define('APP_URL', $__prot . '://' . $__host . $__rel);
    unset($__prot, $__host, $__root, $__doc, $__rel);
}
