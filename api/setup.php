<?php
// Provider first-login setup: choose username + set permanent password.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo  = db();
    $user = current_user($pdo);

    if ($user === null) {
        respond(['ok' => false, 'error' => 'Please log in with your temporary password first.'], 401);
    }
    if ($user['role'] !== 'provider') {
        respond(['ok' => false, 'error' => 'Only providers can complete this setup.'], 403);
    }
    if ($user['status'] !== 'needs_setup') {
        respond(['ok' => false, 'error' => 'Your account is already set up.'], 409);
    }

    $data     = request_input();
    $username = str_field($data, 'username');
    $password = (string)($data['password'] ?? '');
    $phone    = str_field($data, 'phone');
    $location = str_field($data, 'location');
    $uid      = (int)$user['id'];

    $errors = [];
    if (!preg_match('/^[A-Za-z0-9_.]{3,60}$/', $username)) {
        $errors['username'] = 'Username must be 3–60 characters (letters, numbers, _ or .).';
    } elseif (!username_available($pdo, $username, $uid)) {
        $errors['username'] = 'That username is already taken. Please choose another.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }
    if ($errors) {
        respond(['ok' => false, 'errors' => $errors], 422);
    }

    $pdo->beginTransaction();
    try {
        // Update base table: set username, new password hash, activate the account
        $pdo->prepare(
            'UPDATE users SET username=?, password_hash=?, status="active" WHERE id=?'
        )->execute([$username, password_hash($password, PASSWORD_DEFAULT), $uid]);

        // Upsert child table: store/update phone and location in service_providers
        $pdo->prepare(
            'INSERT INTO service_providers (user_id, phone, location)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
               phone    = COALESCE(VALUES(phone),    phone),
               location = COALESCE(VALUES(location), location)'
        )->execute([$uid, $phone ?: null, $location ?: null]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('QuickGanyu setup insert error: ' . $e->getMessage());
        respond(['ok' => false, 'error' => 'Setup failed: ' . $e->getMessage()], 500);
    }

    $updated = fetch_user_by_id($pdo, $uid);
    respond([
        'ok'       => true,
        'message'  => 'Setup complete! Your provider account is now active.',
        'user'     => public_user($updated),
        'redirect' => 'provider-dashboard',
    ]);

} catch (RuntimeException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 503);
} catch (Throwable $e) {
    error_log('QuickGanyu setup error: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'Setup failed due to a server error: ' . $e->getMessage()], 500);
}
