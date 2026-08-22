<?php
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $data       = request_input();
    $identifier = strtolower(str_field($data, 'identifier'));
    $password   = (string)($data['password'] ?? '');

    if ($identifier === '' || $password === '') {
        respond(['ok' => false, 'error' => 'Enter both your username/email and password.'], 422);
    }

    $pdo  = db();
    $stmt = $pdo->prepare(
        'SELECT * FROM users
          WHERE LOWER(username)  = ?
             OR LOWER(email)     = ?
             OR LOWER(full_name) = ?
          LIMIT 1'
    );
    $stmt->execute([$identifier, $identifier, $identifier]);
    $base = $stmt->fetch();

    if ($base === false || !password_verify($password, $base['password_hash'])) {
        respond(['ok' => false, 'error' => 'Invalid login details. Check your username/email and password.'], 401);
    }
    if ($base['status'] === 'suspended') {
        respond(['ok' => false, 'error' => 'This account is suspended. Contact support.'], 403);
    }

    $_SESSION['user_id'] = (int)$base['id'];

    $user = fetch_user_by_id($pdo, (int)$base['id']);
    if ($user === null) {
        respond(['ok' => false, 'error' => 'Account data could not be loaded. Please try again.'], 500);
    }

    $redirect = 'home';
    if ($user['role'] === 'admin') {
        $redirect = 'admin';
    } elseif ($user['role'] === 'provider' && $user['status'] === 'needs_setup') {
        $redirect = 'setup';
    } elseif ($user['role'] === 'provider') {
        $redirect = 'provider-dashboard';
    } elseif ($user['role'] === 'client') {
        $redirect = 'customer-dashboard';
    }

    respond([
        'ok'       => true,
        'message'  => 'Welcome back, ' . $user['full_name'] . '!',
        'user'     => public_user($user),
        'redirect' => $redirect,
    ]);

} catch (RuntimeException $e) {
    // db() connection failure
    respond(['ok' => false, 'error' => $e->getMessage()], 503);
} catch (Throwable $e) {
    error_log('QuickGanyu login error: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'Login failed due to a server error. Please try again.'], 500);
}
