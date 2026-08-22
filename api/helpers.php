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
    $raw  = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    $post = $_POST;
    foreach (['full_name','username','email','phone','password','service_category'] as $f) {
        if (isset($_GET[$f])) {
            $post[$f] = $_GET[$f];
        }
    }
    return $post;
}

/** Trim and normalise a string field, or return default. */
function str_field(array $data, string $key, string $default = ''): string {
    return trim((string)($data[$key] ?? $default));
}

/** Is the username available? (excluding a given user id) */
function username_available(PDO $pdo, string $username, int $excludeId = 0): bool {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
    $stmt->execute([$username, $excludeId]);
    return $stmt->fetch() === false;
}

/** Is the email available? (excluding a given user id) */
function email_available(PDO $pdo, string $email, int $excludeId = 0): bool {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
    $stmt->execute([$email, $excludeId]);
    return $stmt->fetch() === false;
}

/**
 * Fetch a fully-merged user row by id.
 *
 * JOINs the correct child table depending on the role stored in users:
 *   admin    → LEFT JOIN admins           ON admins.user_id = users.id
 *   provider → LEFT JOIN service_providers ON service_providers.user_id = users.id
 *   client   → LEFT JOIN customers         ON customers.user_id = users.id
 *
 * The merged row exposes every column from both tables so the rest of the
 * application can treat it like a single flat record.
 */
function fetch_user_by_id(PDO $pdo, int $id): ?array {
    // First get the base row to know the role.
    $base = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $base->execute([$id]);
    $user = $base->fetch();
    if ($user === false) {
        return null;
    }

    $child = null;
    if ($user['role'] === 'admin') {
        $s = $pdo->prepare('SELECT * FROM admins WHERE user_id = ? LIMIT 1');
        $s->execute([$id]);
        $child = $s->fetch() ?: [];
    } elseif ($user['role'] === 'provider') {
        $s = $pdo->prepare('SELECT * FROM service_providers WHERE user_id = ? LIMIT 1');
        $s->execute([$id]);
        $child = $s->fetch() ?: [];
    } elseif ($user['role'] === 'client') {
        $s = $pdo->prepare('SELECT * FROM customers WHERE user_id = ? LIMIT 1');
        $s->execute([$id]);
        $child = $s->fetch() ?: [];
    }

    // Merge: child columns win on conflict (e.g. both have user_id — keep users.id).
    if ($child) {
        unset($child['user_id']); // avoid overwriting users.id
        $user = array_merge($user, $child);
    }

    return $user;
}

/** Return the currently logged-in user (merged with child table), or null. */
function current_user(PDO $pdo): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return fetch_user_by_id($pdo, (int)$_SESSION['user_id']);
}

/**
 * Send a branded HTML email via Gmail SMTP.
 * Also saves a local copy to /mailbox for dev inspection.
 */
function send_mail(string $to, string $subject, string $htmlBody): void {
    require_once __DIR__ . '/mailer.php';

    $full = email_template($subject, $htmlBody);
    $sent = smtp_send($to, $subject, $full);

    $dir = __DIR__ . '/../mailbox';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $fname = $dir . '/sent-' . date('Ymd-His') . '-' . substr(md5($to . microtime()), 0, 8) . '.html';
    @file_put_contents($fname, $full);

    if (!$sent) {
        error_log("QuickGanyu mailer: SMTP failed for $to – $subject");
    }
}

/** Strip sensitive fields before returning a user to the client. */
function public_user(array $row): array {
    unset($row['password_hash'], $row['temp_password_hash']);
    return $row;
}
