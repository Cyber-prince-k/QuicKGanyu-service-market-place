<?php
// QuickGanyu – API configuration & database connection

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'quickganyu');
define('DB_USER', 'root');
define('DB_PASS', '');

/**
 * Return a singleton PDO connection.
 * Throws a RuntimeException (caught by callers) on failure.
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Log the real error; return a safe message to the client.
            error_log('QuickGanyu DB connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed. Please try again later.');
        }
    }
    return $pdo;
}

if (session_status() === PHP_SESSION_NONE) {
    session_name('QUICKGANYU_SESSION');
    session_start();
}
