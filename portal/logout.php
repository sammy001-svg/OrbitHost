<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
portal_start();
session_unset();
session_destroy();
header('Location: ' . PORTAL_URL . '/login.php');
exit;
