<?php
/**
 * Orbit Cloud — ticket attachment storage
 *
 * Client/admin ticket replies can attach one file. These can contain
 * sensitive material (cPanel screenshots, error logs with server paths)
 * so they're never linked to directly: stored under a random
 * server-generated filename in a deny-all directory, and only ever
 * served back out through a gatekeeper script (admin/tickets/attachment.php,
 * portal/tickets/attachment.php) that checks the requester actually has
 * a reason to see that specific ticket first.
 */
require_once __DIR__ . '/db.php';

final class TicketAttachment
{
    public const MAX_BYTES = 8 * 1024 * 1024; // 8 MB

    /** Real (finfo-verified) MIME type => stored extension. Extension is never trusted from the client. */
    private const MIME_EXT = [
        'image/png'       => 'png',
        'image/jpeg'      => 'jpg',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
        'text/plain'      => 'txt',
        'application/zip' => 'zip',
    ];

    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS ticket_attachments (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ticket_id     INT UNSIGNED NOT NULL,
                reply_id      INT UNSIGNED NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                stored_name   VARCHAR(255) NOT NULL,
                mime_type     VARCHAR(100) NOT NULL,
                size_bytes    INT UNSIGNED NOT NULL,
                created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ta_reply (reply_id),
                FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                FOREIGN KEY (reply_id)  REFERENCES ticket_replies(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) { /* no ALTER/CREATE privilege — attachments simply unavailable */ }
    }

    private static function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/uploads/tickets';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) @file_put_contents($htaccess, "Require all denied\ndeny from all\n");
        return $dir;
    }

    /**
     * Stores $_FILES['attachment']-shaped array against a reply. A missing
     * file is not an error — attachments are always optional.
     * @return array{ok:bool, message?:string, id?:int}
     */
    public static function store(array $file, int $ticketId, int $replyId): array
    {
        if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload failed — please try again.'];
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'message' => 'Invalid upload.'];
        }
        if ($file['size'] > self::MAX_BYTES) {
            return ['ok' => false, 'message' => 'That file is too large (max 8 MB).'];
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::MIME_EXT[$mime])) {
            return ['ok' => false, 'message' => "That file type isn't supported. Allowed: images, PDF, TXT, ZIP."];
        }

        self::ensureTable();
        $stored = 'ta-' . bin2hex(random_bytes(12)) . '.' . self::MIME_EXT[$mime];
        if (!move_uploaded_file($file['tmp_name'], self::dir() . '/' . $stored)) {
            return ['ok' => false, 'message' => 'Could not save the uploaded file.'];
        }

        db()->prepare('INSERT INTO ticket_attachments (ticket_id, reply_id, original_name, stored_name, mime_type, size_bytes) VALUES (?,?,?,?,?,?)')
            ->execute([$ticketId, $replyId, self::safeFilename($file['name']), $stored, $mime, (int) $file['size']]);
        return ['ok' => true, 'id' => (int) db()->lastInsertId()];
    }

    public static function forReply(int $replyId): array
    {
        try {
            self::ensureTable();
            $stmt = db()->prepare('SELECT * FROM ticket_attachments WHERE reply_id = ?');
            $stmt->execute([$replyId]);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function find(int $id): ?array
    {
        try {
            $stmt = db()->prepare('SELECT * FROM ticket_attachments WHERE id = ?');
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Which ticket a given attachment belongs to — used by the portal gatekeeper's ownership check. */
    public static function ticketIdFor(int $attachmentId): int
    {
        $att = self::find($attachmentId);
        return $att ? (int) $att['ticket_id'] : 0;
    }

    public static function path(array $attachment): string
    {
        return self::dir() . '/' . $attachment['stored_name'];
    }

    public static function icon(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/')     => 'fa-file-image',
            $mime === 'application/pdf'           => 'fa-file-pdf',
            $mime === 'application/zip'           => 'fa-file-zipper',
            default                               => 'fa-file-lines',
        };
    }

    /** True for types a browser can render directly; false forces a download instead. */
    public static function isInlineable(string $mime): bool
    {
        return str_starts_with($mime, 'image/') || $mime === 'application/pdf';
    }

    private static function safeFilename(string $name): string
    {
        $name = str_replace(["\r", "\n", '"', "\0"], '', $name);
        return mb_strimwidth($name, 0, 255, '');
    }
}
