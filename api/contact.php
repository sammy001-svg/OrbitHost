<?php
/**
 * OrbitHost — public contact form backend.
 * Creates a support ticket from a website visitor (no client account
 * required) and notifies admins in-app + by email, using the same
 * Notifier pipeline as every other ticket in the system.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../admin/includes/config.php';
require_once __DIR__ . '/../admin/includes/db.php';
require_once __DIR__ . '/../admin/includes/functions.php';
require_once __DIR__ . '/../admin/includes/Notifier.php';

function jout(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jout(['ok' => false, 'error' => 'Method not allowed.'], 405);

// Honeypot: a real visitor never fills this hidden field; a bot usually does.
if (trim($_POST['website'] ?? '') !== '') jout(['ok' => true]); // pretend success, drop silently

$first   = trim($_POST['first_name'] ?? '');
$last    = trim($_POST['last_name']  ?? '');
$email   = trim($_POST['email']      ?? '');
$phone   = trim($_POST['phone']      ?? '');
$topic   = trim($_POST['subject']    ?? '');
$message = trim($_POST['message']    ?? '');

$errors = [];
if ($first === '')                                   $errors[] = 'First name is required.';
if ($last === '')                                     $errors[] = 'Last name is required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))       $errors[] = 'A valid email address is required.';
if ($topic === '')                                    $errors[] = 'Please select a subject.';
if (mb_strlen($message) < 20)                         $errors[] = 'Please write at least 20 characters.';
if ($errors) jout(['ok' => false, 'error' => implode(' ', $errors)], 400);

// Auto-migrate: guest contact columns on tickets (safe no-op if already present)
try {
    db()->exec("ALTER TABLE tickets
        ADD COLUMN IF NOT EXISTS guest_name  VARCHAR(150) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS guest_email VARCHAR(150) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS guest_phone VARCHAR(30)  DEFAULT NULL");
} catch (\Throwable $e) { /* older MySQL without IF NOT EXISTS support on ALTER — ignore if columns already exist */ }

$department_map = [
    'Sales & New Account'          => 'sales',
    'Technical Support'            => 'technical',
    'Billing & Payments'           => 'billing',
    'Domain Services'              => 'technical',
    'Reseller Enquiry'             => 'sales',
    'Enterprise / Custom Quote'    => 'sales',
];
$department = $department_map[$topic] ?? 'general';
$name = trim("$first $last");

// If this email matches an existing client, attach the ticket to their
// account so it shows in their portal history too — otherwise it's a
// guest ticket (client_id NULL, guest_* columns hold their details).
$client_id = null;
$stmt = db()->prepare('SELECT id FROM clients WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
if ($row = $stmt->fetch()) $client_id = (int) $row['id'];

$num = generate_ticket_number();
db()->prepare(
    'INSERT INTO tickets (ticket_number, client_id, subject, department, priority, status, guest_name, guest_email, guest_phone)
     VALUES (?,?,?,?,"medium","open",?,?,?)'
)->execute([$num, $client_id, $topic, $department, $client_id ? null : $name, $client_id ? null : $email, $client_id ? null : ($phone ?: null)]);
$tid = (int) db()->lastInsertId();

db()->prepare('INSERT INTO ticket_replies (ticket_id, sender_type, sender_name, message) VALUES (?,?,?,?)')
    ->execute([$tid, 'client', $name, $message]);

Notifier::sendToAllAdmins('ticket_opened_admin', [
    'client_name'   => $name,
    'subject'       => $topic,
    'ticket_number' => $num,
    'priority'      => 'Medium',
    'link'          => APP_URL . '/tickets/view.php?id=' . $tid,
]);

jout(['ok' => true, 'ticket_number' => $num]);
