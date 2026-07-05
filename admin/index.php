<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
auth_start();
$dest = empty($_SESSION['admin_id']) ? APP_URL . '/login.php' : APP_URL . '/dashboard.php';
header('Location: ' . $dest);
exit;
