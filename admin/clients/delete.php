<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/clients/');
    exit;
}

csrf_verify();
require_role('admin', APP_URL . '/clients/');

$id = (int)($_POST['id'] ?? 0);
if ($id) {
    $stmt = db()->prepare('SELECT first_name, last_name FROM clients WHERE id = ?');
    $stmt->execute([$id]);
    $c = $stmt->fetch();
    if ($c) {
        db()->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        log_activity('delete_client', 'client', $id, "Deleted client {$c['first_name']} {$c['last_name']}");
        flash_set('success', "Client {$c['first_name']} {$c['last_name']} deleted.");
    }
}

header('Location: ' . APP_URL . '/clients/');
exit;
