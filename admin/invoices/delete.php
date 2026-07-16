<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/invoices/index.php');
    exit;
}

csrf_verify();
require_role('admin', APP_URL . '/invoices/index.php');

$id = (int)($_POST['id'] ?? 0);
if ($id) {
    $stmt = db()->prepare('SELECT invoice_number FROM invoices WHERE id = ?');
    $stmt->execute([$id]);
    $inv = $stmt->fetch();
    if ($inv) {
        db()->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        log_activity('delete_invoice', 'invoice', $id, "Deleted invoice {$inv['invoice_number']}");
        flash_set('success', "Invoice {$inv['invoice_number']} deleted.");
    }
}

header('Location: ' . APP_URL . '/invoices/index.php');
exit;
