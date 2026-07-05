<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
auth_start();
session_unset();
session_destroy();
header('Location: ' . APP_URL . '/login.php');
exit;
