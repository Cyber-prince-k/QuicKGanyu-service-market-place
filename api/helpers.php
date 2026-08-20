<?php
require_once __DIR__ . '/config.php';

/** Send a JSON response and stop. */
function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

/** Read JSON body or form data into an array. */
function request_input(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    $post = $_POST;
    foreach (['full_name', 'username', 'email', 'phone', 'password', 'service_category'] as $field) {
        if (isset($_GET[$field])) {
            $post[$field] = $_GET[$field];
        }
    }
    return $post;
}

/** Trim and normalize a string field, or return default. */
function str_field(array $data, string $key, string $default = ''): string {
    $value = $data[$key] ?? $default;
    return trim((string)$value);
}

/** Is the username available? (excluding a given user id) */
function username_available(PDO $pdo, string $username, int $excludeId = 0): bool {
    $stmt = $pdo->prepare(
        'SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1'
    );
    $stmt->execute([$username, $excludeId]);
    return $stmt->fetch() === false;
}

/** Is the email available? (excluding a given user id) */
function email_available(PDO $pdo, string $email, int $excludeId = 0): bool {
    $stmt = $pdo->prepare(
        'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1'
    );
    $stmt->execute([$email, $excludeId]);
    return $stmt->fetch() === false;
}

/** Current logged-in user row, or null. */
function current_user(PDO $pdo): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user === false ? null : $user;
}

/**
 * Send an email.
 * Writes a copy to /mailbox so it works in local dev without an SMTP server,
 * and also attempts a real mail() send for production.
 */
function send_mail(string $to, string $subject, string $htmlBody): void {
    $dir = __DIR__ . '/../mailbox';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $filename = $dir . '/sent-' . date('Ymd-His') . '-' . substr(md5($to . microtime()), 0, 8) . '.html';
    $full = "<h2 style='font-family:sans-serif'>$subject</h2><p>To: " . htmlspecialchars($to, ENT_QUOTES) . "</p><hr>" . $htmlBody;
    @file_put_contents($filename, $full);

    $headers = "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "From: QuickGanyu <no-reply@quickganyu.com>\r\n";
    @mail($to, $subject, $htmlBody, $headers);
}

/** Public view of a user (never expose password hashes). */
function public_user(array $row): array {
    unset($row['password_hash'], $row['temp_password_hash']);
    return $row;
}
