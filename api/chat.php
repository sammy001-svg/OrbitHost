<?php
/**
 * OrbitHost — Live chat API (public)
 * Powers the floating "OrbitHost Support" widget on the website.
 *
 *   POST action=start   {name?, email?, message}  → { ok, conversation, token }
 *   POST action=send    {conversation, token, message}
 *   GET  action=poll    ?conversation=&token=&after=<last msg id>
 *
 * Visitors are identified by a random token stored client-side, so no
 * login is required. Admin replies come from the admin Live Chat inbox.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../admin/includes/config.php';
require_once __DIR__ . '/../admin/includes/db.php';

function jout(array $d, int $c = 200): void { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_SLASHES); exit; }

// ── Ensure tables exist (schema_v6.sql documents these) ──
try {
    db()->exec("CREATE TABLE IF NOT EXISTS chat_conversations (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        visitor_token VARCHAR(64)  NOT NULL,
        name          VARCHAR(100) DEFAULT NULL,
        email         VARCHAR(150) DEFAULT NULL,
        page          VARCHAR(255) DEFAULT NULL,
        status        ENUM('open','closed') NOT NULL DEFAULT 'open',
        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cc_token (visitor_token),
        INDEX idx_cc_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        conversation_id INT UNSIGNED NOT NULL,
        sender          ENUM('visitor','admin') NOT NULL,
        sender_name     VARCHAR(100) DEFAULT NULL,
        message         TEXT NOT NULL,
        created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        admin_read      TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_cm_conv (conversation_id),
        FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {
    jout(['ok' => false, 'error' => 'Chat is temporarily unavailable.'], 503);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Quick diagnostic: /api/chat.php?action=health
if ($action === 'health') {
    $convs = (int) db()->query('SELECT COUNT(*) FROM chat_conversations')->fetchColumn();
    $msgs  = (int) db()->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();
    jout(['ok' => true, 'tables' => 'ready', 'conversations' => $convs, 'messages' => $msgs]);
}

// Resolve + authorize an existing conversation by id + token
function conv_auth(): array
{
    $id    = (int)($_POST['conversation'] ?? $_GET['conversation'] ?? 0);
    $token = trim($_POST['token'] ?? $_GET['token'] ?? '');
    if (!$id || strlen($token) < 20) jout(['ok' => false, 'error' => 'Invalid conversation.'], 403);
    $stmt = db()->prepare('SELECT * FROM chat_conversations WHERE id = ? AND visitor_token = ?');
    $stmt->execute([$id, $token]);
    $conv = $stmt->fetch();
    if (!$conv) jout(['ok' => false, 'error' => 'Conversation not found.'], 404);
    return $conv;
}

if ($action === 'start') {
    $message = trim($_POST['message'] ?? '');
    if ($message === '' || mb_strlen($message) > 2000) jout(['ok' => false, 'error' => 'Type a message to start the chat.'], 400);

    $name  = mb_substr(trim($_POST['name'] ?? ''), 0, 100);
    $email = trim($_POST['email'] ?? '');
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

    $token = bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO chat_conversations (visitor_token, name, email, page) VALUES (?,?,?,?)')
        ->execute([$token, $name ?: null, $email ?: null, mb_substr($_SERVER['HTTP_REFERER'] ?? '', 0, 255)]);
    $cid = (int) db()->lastInsertId();

    db()->prepare('INSERT INTO chat_messages (conversation_id, sender, sender_name, message) VALUES (?,?,?,?)')
        ->execute([$cid, 'visitor', $name ?: 'Visitor', $message]);

    jout(['ok' => true, 'conversation' => $cid, 'token' => $token]);
}

if ($action === 'send') {
    $conv = conv_auth();
    $message = trim($_POST['message'] ?? '');
    if ($message === '' || mb_strlen($message) > 2000) jout(['ok' => false, 'error' => 'Empty message.'], 400);
    db()->prepare('INSERT INTO chat_messages (conversation_id, sender, sender_name, message) VALUES (?,?,?,?)')
        ->execute([(int)$conv['id'], 'visitor', $conv['name'] ?: 'Visitor', $message]);
    db()->prepare('UPDATE chat_conversations SET updated_at = NOW(), status = "open" WHERE id = ?')->execute([(int)$conv['id']]);
    jout(['ok' => true]);
}

if ($action === 'poll') {
    $conv  = conv_auth();
    $after = (int)($_GET['after'] ?? 0);
    $stmt  = db()->prepare('SELECT id, sender, sender_name, message, DATE_FORMAT(created_at, "%H:%i") t
                            FROM chat_messages WHERE conversation_id = ? AND id > ? ORDER BY id LIMIT 100');
    $stmt->execute([(int)$conv['id'], $after]);
    jout(['ok' => true, 'status' => $conv['status'], 'messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

jout(['ok' => false, 'error' => 'Unknown action.'], 400);
