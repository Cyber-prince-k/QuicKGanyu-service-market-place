<?php
require_once __DIR__ . '/config.php';

/** Send a JSON response and stop. */
function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

/** Read JSON body or $_POST into an array. */
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
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
        $stmt->execute([$username, $excludeId]);
        return $stmt->fetch() === false;
    } catch (Throwable $e) {
        error_log('QuickGanyu username_available error: ' . $e->getMessage());
        return false; // treat as taken on error — safer than allowing duplicates
    }
}

/** Is the full name available? (excluding a given user id) */
function full_name_available(PDO $pdo, string $fullName, int $excludeId = 0): bool {
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE full_name = ? AND id <> ? LIMIT 1');
        $stmt->execute([$fullName, $excludeId]);
        return $stmt->fetch() === false;
    } catch (Throwable $e) {
        error_log('QuickGanyu full_name_available error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Fetch a fully-merged user row by id.
 * Reads users (base) then LEFT JOINs the correct child table based on role:
 *   admin    → admins
 *   provider → service_providers
 *   client   → customers
 */
function fetch_user_by_id(PDO $pdo, int $id): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if ($user === false) {
            return null;
        }

        $child = [];
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

        if ($child) {
            unset($child['user_id']); // don't overwrite users.id
            $user = array_merge($user, $child);
        }

        if ($user['role'] === 'provider') {
            $user['profile_complete'] = provider_profile_complete($pdo, $id, $user);
        }

        return $user;
    } catch (Throwable $e) {
        error_log('QuickGanyu fetch_user_by_id error: ' . $e->getMessage());
        return null;
    }
}

/** Whether a provider has supplied all details required before being bookable. */
function provider_profile_complete(PDO $pdo, int $providerId, array $provider = []): bool {
    try {
        if (!$provider) {
            $provider = fetch_user_by_id($pdo, $providerId) ?? [];
        }
        foreach (['phone', 'location', 'service_category', 'skills', 'qualifications', 'about'] as $field) {
            if (trim((string)($provider[$field] ?? '')) === '') return false;
        }
        if ((int)($provider['experience_years'] ?? 0) < 1) return false;

        $portfolio = $pdo->prepare('SELECT 1 FROM provider_portfolio WHERE provider_id = ? LIMIT 1');
        $portfolio->execute([$providerId]);
        if ($portfolio->fetch() === false) return false;

        $testimonials = $pdo->prepare('SELECT 1 FROM provider_testimonials WHERE provider_id = ? LIMIT 1');
        $testimonials->execute([$providerId]);
        return $testimonials->fetch() !== false;
    } catch (Throwable $e) {
        error_log('QuickGanyu provider_profile_complete error: ' . $e->getMessage());
        return false;
    }
}

/** Return provider-owned portfolio and testimonial records. */
function provider_evidence(PDO $pdo, int $providerId): array {
    try {
        $portfolio = $pdo->prepare(
            'SELECT id, media_type, file_path, caption, created_at FROM provider_portfolio WHERE provider_id=? ORDER BY created_at DESC'
        );
        $portfolio->execute([$providerId]);
        $testimonials = $pdo->prepare(
            'SELECT id, client_name, testimonial, created_at FROM provider_testimonials WHERE provider_id=? ORDER BY created_at DESC'
        );
        $testimonials->execute([$providerId]);
        return ['portfolio' => $portfolio->fetchAll(), 'testimonials' => $testimonials->fetchAll()];
    } catch (Throwable $e) {
        error_log('QuickGanyu provider_evidence error: ' . $e->getMessage());
        return ['portfolio' => [], 'testimonials' => []];
    }
}

/** Return a provider's published product/service listings and their media. */
function provider_products(PDO $pdo, int $providerId): array {
    try {
        $products = $pdo->prepare(
            'SELECT id, title, description, created_at FROM provider_products WHERE provider_id=? ORDER BY created_at DESC'
        );
        $products->execute([$providerId]);
        $items = $products->fetchAll();
        if (!$items) return [];

        $media = $pdo->prepare(
            'SELECT id, media_type, file_path FROM provider_product_media WHERE product_id=? ORDER BY created_at ASC'
        );
        foreach ($items as &$item) {
            $media->execute([(int)$item['id']]);
            $item['media'] = $media->fetchAll();
        }
        unset($item);
        return $items;
    } catch (Throwable $e) {
        error_log('QuickGanyu provider_products error: ' . $e->getMessage());
        return [];
    }
}

/** Return the currently logged-in user (merged with child table), or null. */
function current_user(PDO $pdo): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return fetch_user_by_id($pdo, (int)$_SESSION['user_id']);
}

/** Send a branded HTML email. */
function send_mail(string $to, string $subject, string $htmlBody): void {
    try {
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
    } catch (Throwable $e) {
        error_log("QuickGanyu send_mail exception for $to: " . $e->getMessage());
        // Email failure must never crash the main flow
    }
}

/** Strip sensitive fields before returning a user to the client. */
function public_user(array $row): array {
    unset($row['password_hash'], $row['temp_password_hash']);
    return $row;
}
