<?php
// Legacy settings page — superseded by the registry-driven Providers hub.
require_once '../includes/config.php';
require_once '../includes/auth.php';
auth_check();
header('Location: ' . APP_URL . '/integrations/');
exit;
