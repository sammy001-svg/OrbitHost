<?php
// Reuse admin DB + constants
require_once dirname(__DIR__, 2) . '/admin/includes/config.php';

// Portal-specific URL
if (!defined('PORTAL_URL')) {
    $__prot = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $__host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $__root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $__doc  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $__rel  = str_replace($__doc, '', $__root);
    define('PORTAL_URL', $__prot . '://' . $__host . $__rel);
    unset($__prot, $__host, $__root, $__doc, $__rel);
}

define('PORTAL_SESSION', 'orbit_portal');
