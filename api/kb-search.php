<?php
/**
 * Orbit Cloud — Knowledge Base live search API.
 * Used by the "search before you submit a ticket" widget in the portal.
 *   GET ?q=<term>  →  { ok, results: [{ title, slug, excerpt }] }
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../admin/includes/config.php';
require_once __DIR__ . '/../admin/includes/db.php';

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'results' => []]);
    exit;
}

try {
    $like = '%' . $q . '%';
    $stmt = db()->prepare('SELECT title, slug, excerpt FROM kb_articles WHERE is_published = 1 AND (title LIKE ? OR excerpt LIKE ? OR body LIKE ?) ORDER BY sort_order LIMIT 5');
    $stmt->execute([$like, $like, $like]);
    echo json_encode(['ok' => true, 'results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => true, 'results' => []]); // KB not set up yet — fail quiet, not an error to the widget
}
