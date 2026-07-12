<?php
// ── Load .env from project root ───────────────────────────────
(function () {
    $env_file = dirname(__DIR__, 2) . '/.env';
    if (!is_readable($env_file)) {
        die('<b>Configuration error:</b> .env file not found at ' . htmlspecialchars($env_file) .
            '. Copy .env from the project root and fill in your database credentials.');
    }
    $values = parse_ini_file($env_file);
    if ($values === false) {
        die('<b>Configuration error:</b> .env file could not be parsed. Check its syntax.');
    }
    foreach ($values as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
})();

// ── Defaults (used if .env omits them) ────────────────────────
defined('DB_HOST')          || define('DB_HOST',    'localhost');
defined('DB_CHARSET')       || define('DB_CHARSET', 'utf8mb4');
defined('APP_NAME')         || define('APP_NAME',   'OrbitHost Admin');
defined('TAX_RATE')         || define('TAX_RATE',   16);
defined('CURRENCY')         || define('CURRENCY',   'USD');
defined('PER_PAGE')         || define('PER_PAGE',   20);
defined('SESSION_TIMEOUT')  || define('SESSION_TIMEOUT', 7200);

// ── Auto-detect APP_URL if not set in .env ────────────────────
// Using SCRIPT_NAME is far more reliable than DOCUMENT_ROOT math
// because DOCUMENT_ROOT can differ from the real filesystem path
// (common on XAMPP with projects outside htdocs, or symlinked dirs).
if (!defined('APP_URL')) {
    $__prot   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $__host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    if (!defined('SETUP_MODE')) {
        // Normal context: config.php lives in admin/includes/
        // Walk up from the current script's URL path to get /admin
        $__script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        // Find the position of /admin/ in the URL path and trim to it
        $__pos    = strpos($__script, '/admin/');
        if ($__pos !== false) {
            $__rel = substr($__script, 0, $__pos) . '/admin';
        } else {
            // Fallback: derive from filesystem vs DOCUMENT_ROOT
            $__root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
            $__doc  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
            $__rel  = str_replace($__doc, '', $__root);
        }
    } else {
        // setup.php context — /admin is one level below project root
        $__script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $__pos    = strpos($__script, '/admin/');
        if ($__pos !== false) {
            $__rel = substr($__script, 0, $__pos) . '/admin';
        } else {
            $__root = rtrim(str_replace('\\', '/', dirname(dirname(__DIR__))), '/');
            $__doc  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
            $__rel  = str_replace($__doc, '', $__root) . '/admin';
        }
    }

    define('APP_URL', $__prot . '://' . $__host . $__rel);
    unset($__prot, $__host, $__script, $__pos, $__rel, $__root, $__doc);
}

