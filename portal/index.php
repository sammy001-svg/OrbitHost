<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
portal_start();
$dest = empty($_SESSION['client_id']) ? PORTAL_URL . '/login.php' : PORTAL_URL . '/dashboard.php';
header('Location: ' . $dest);
exit;
