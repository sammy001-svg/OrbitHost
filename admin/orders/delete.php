<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/orders/');
    exit;
}

csrf_verify();

$id = (int)($_POST['id'] ?? 0);
if ($id) {
    db()->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
    log_activity('delete_order', 'order', $id, "Deleted order #$id");
    flash_set('success', 'Order deleted.');
}

header('Location: ' . APP_URL . '/orders/');
exit;
