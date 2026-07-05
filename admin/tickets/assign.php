<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/tickets/');
    exit;
}

csrf_verify();

$id          = (int)($_POST['id'] ?? 0);
$assigned_to = (int)($_POST['assigned_to'] ?? 0) ?: null;

if ($id) {
    db()->prepare('UPDATE tickets SET assigned_to=? WHERE id=?')->execute([$assigned_to, $id]);
    flash_set('success', 'Assignment updated.');
}

header('Location: ' . APP_URL . '/tickets/view.php?id=' . $id);
exit;
