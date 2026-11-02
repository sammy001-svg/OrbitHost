<?php
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_FOUND_ROWS   => true,  // rowCount() = matched rows, not changed rows
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die('<h2 style="font-family:sans-serif;color:var(--danger-fg)">Database connection failed</h2><p>' .
                htmlspecialchars($e->getMessage()) . '</p>' .
                '<p>Check DB_HOST / DB_NAME / DB_USER / DB_PASS in <code>includes/config.php</code>.</p>');
        }
        // One clock for the whole app. PHP and MySQL are often configured in
        // different time zones on shared hosting (PHP Africa/Nairobi, MySQL
        // UTC, or the reverse). Code that writes a time from PHP and compares
        // it with MySQL's NOW() was then hours off — e.g. queued SMS stayed
        // "not due yet" for three hours. Make MySQL use PHP's zone (set in
        // config.php from APP_TIMEZONE) for this connection.
        try {
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        } catch (\Throwable $e) { /* keep going on the server's default */ }
    }
    return $pdo;
}
