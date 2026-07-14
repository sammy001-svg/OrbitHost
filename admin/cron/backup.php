<?php
/**
 * Orbit Cloud — automated database backup
 *
 * Run daily via cPanel Cron Jobs (alongside reminders.php, billing.php,
 * payments.php):
 *   /usr/local/bin/php /home/USERNAME/public_html/admin/cron/backup.php
 * or over HTTP with CRON_SECRET set in .env:
 *   https://yourdomain.com/admin/cron/backup.php?token=YOUR_SECRET
 *
 * Pure-PHP SQL dump — deliberately does NOT shell out to the `mysqldump`
 * binary, because shared hosts commonly disable exec()/shell_exec()
 * entirely, which would make a mysqldump-based backup silently
 * impossible on exactly the hosting this app targets. Walks every table
 * via the existing PDO connection, writes CREATE TABLE + INSERT
 * statements, gzip-compresses as it streams (safe for large tables —
 * never holds the whole dump in memory), then rotates old backups.
 *
 * IMPORTANT — this is LOCAL rotation, not true off-site backup. A PHP
 * script on the same server can't safely push to external storage
 * without credentials this app doesn't hold. For real disaster
 * recovery (the server itself failing), also enable your host's own
 * remote-backup feature (cPanel > Backup Wizard, or rsync/rclone to
 * external storage) — this cron protects you from bad data/queries,
 * not from losing the server.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Notifier.php';

$is_cli = PHP_SAPI === 'cli';
if (!$is_cli) {
    $secret = defined('CRON_SECRET') ? CRON_SECRET : '';
    if ($secret === '' || !hash_equals($secret, $_GET['token'] ?? '')) {
        http_response_code(403);
        exit("Forbidden. Set CRON_SECRET in .env and pass ?token=<secret>, or run via CLI.\n");
    }
    header('Content-Type: text/plain');
}

@set_time_limit(0);
$keep_days  = 14;
$backup_dir = dirname(__DIR__, 2) . '/backups';

if (!is_dir($backup_dir) && !@mkdir($backup_dir, 0750, true)) {
    echo "[" . date('Y-m-d H:i:s') . "] BACKUP FAILED: could not create backups/ directory (check permissions).\n";
    exit(1);
}
// Deny-all, written once — belt-and-suspenders alongside the .gitignore entry.
$htaccess = $backup_dir . '/.htaccess';
if (!is_file($htaccess)) {
    @file_put_contents($htaccess, "Require all denied\ndeny from all\n");
}

$filename = 'db-backup-' . date('Y-m-d-His') . '.sql.gz';
$filepath = $backup_dir . '/' . $filename;
$gz = null;

try {
    $pdo    = db();
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    $gz = gzopen($filepath, 'wb9');
    if (!$gz) throw new RuntimeException('Could not open backup file for writing.');

    gzwrite($gz, "-- Orbit Cloud database backup — " . date('Y-m-d H:i:s') . "\n");
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    foreach ($tables as $table) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_ASSOC);
        gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n" . $create['Create Table'] . ";\n\n");

        $stmt = $pdo->query('SELECT * FROM `' . $table . '`');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols = implode(',', array_map(fn($c) => "`{$c}`", array_keys($row)));
            $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row)));
            gzwrite($gz, "INSERT INTO `{$table}` ({$cols}) VALUES ({$vals});\n");
        }
        gzwrite($gz, "\n");
    }

    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
    $gz = null;

    $size = filesize($filepath);
    if ($size === false || $size < 100) {
        throw new RuntimeException('Backup file looks empty or truncated.');
    }

    $removed = 0;
    foreach (glob($backup_dir . '/db-backup-*.sql.gz') ?: [] as $old) {
        if (filemtime($old) < strtotime("-{$keep_days} days")) {
            @unlink($old);
            $removed++;
        }
    }

    echo sprintf(
        "[%s] Backup complete — %s (%s), %d tables, %d old backup(s) rotated out.\n",
        date('Y-m-d H:i:s'), $filename, format_bytes($size), count($tables), $removed
    );

} catch (\Throwable $e) {
    if (is_resource($gz)) gzclose($gz);
    if (is_file($filepath)) @unlink($filepath); // never leave a half-written dump behind
    try {
        Notifier::sendToAllAdmins('order_new_admin', [
            'client_name' => 'System', 'item' => 'Database backup FAILED: ' . $e->getMessage(),
            'amount' => '—', 'gateway' => 'backup', 'link' => APP_URL . '/dashboard.php',
        ]);
    } catch (\Throwable $e2) { /* notification failure shouldn't mask the real error below */ }
    echo "[" . date('Y-m-d H:i:s') . "] BACKUP FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
