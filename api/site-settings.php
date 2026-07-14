<?php
/**
 * Orbit Cloud — Public site settings API
 * Read-only. Powers js/site-settings.js on every public page (branding,
 * favicon, announcement bar, footer, contact page content).
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=120');

require_once __DIR__ . '/../admin/includes/config.php';
require_once __DIR__ . '/../admin/includes/SiteSettings.php';

try {
    echo json_encode(['ok' => true, 'settings' => SiteSettings::all()], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Settings unavailable.']);
}
