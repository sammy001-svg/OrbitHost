<?php
/** Orbit Cloud — admin notification bell polling endpoint. */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/Notifier.php';

auth_check();
header('Content-Type: application/json');
$admin_id = (int) current_admin()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        Notifier::markRead((int) ($_POST['id'] ?? 0), 'admin', $admin_id);
    } elseif ($action === 'mark_all_read') {
        Notifier::markAllRead('admin', $admin_id);
    }
}

$notifs = Notifier::listFor('admin', $admin_id, 8);
echo json_encode([
    'ok'     => true,
    'unread' => Notifier::unreadCount('admin', $admin_id),
    'items'  => array_map(fn($n) => [
        'id' => (int) $n['id'], 'title' => $n['title'], 'message' => $n['message'],
        'is_read' => (bool) $n['is_read'], 'time' => time_ago($n['created_at']),
        'link' => APP_URL . '/notifications/index.php?open=' . $n['id'],
    ], $notifs),
]);
