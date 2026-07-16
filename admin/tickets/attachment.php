<?php
/** Orbit Cloud — serves a ticket attachment to a logged-in admin. */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/TicketAttachment.php';

auth_check();

$id  = (int) ($_GET['id'] ?? 0);
$att = TicketAttachment::find($id);
if (!$att) { http_response_code(404); exit('Attachment not found.'); }

$path = TicketAttachment::path($att);
if (!is_file($path)) { http_response_code(404); exit('Attachment not found.'); }

$disposition = TicketAttachment::isInlineable($att['mime_type']) ? 'inline' : 'attachment';
header('Content-Type: ' . $att['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . basename($att['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($path);
