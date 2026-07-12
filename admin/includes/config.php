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
