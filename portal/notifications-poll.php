<?php
/** Orbit Cloud — portal notification bell polling endpoint. */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';
require_once dirname(__DIR__) . '/admin/includes/Notifier.php';

portal_check();
header('Content-Type: application/json');
$client_id = (int) current_client()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        Notifier::markRead((int) ($_POST['id'] ?? 0), 'client', $client_id);
    } elseif ($action === 'mark_all_read') {
        Notifier::markAllRead('client', $client_id);
    }
}

$notifs = Notifier::listFor('client', $client_id, 8);
echo json_encode([
    'ok'     => true,
    'unread' => Notifier::unreadCount('client', $client_id),
    'items'  => array_map(fn($n) => [
        'id' => (int) $n['id'], 'title' => $n['title'], 'message' => $n['message'],
        'is_read' => (bool) $n['is_read'], 'time' => time_ago($n['created_at']),
        'link' => PORTAL_URL . '/notifications.php?open=' . $n['id'],
    ], $notifs),
]);
