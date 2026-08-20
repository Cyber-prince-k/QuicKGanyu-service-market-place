<?php
// Login for admins, clients and providers.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

$data = request_input();
$identifier = strtolower(str_field($data, 'identifier')); // username OR email
$password   = (string)($data['password'] ?? '');

if ($identifier === '' || $password === '') {
    respond(['ok' => false, 'error' => 'Enter both your username/email and password.'], 422);
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT * FROM users WHERE LOWER(username) = ? OR LOWER(email) = ? OR LOWER(full_name) = ? LIMIT 1'
);
$stmt->execute([$identifier, $identifier, $identifier]);
$user = $stmt->fetch();

if ($user === false || !password_verify($password, $user['password_hash'])) {
    // Constant message: do not reveal which field was wrong.
    respond(['ok' => false, 'error' => 'Invalid login details. Check your username/email and password.'], 401);
}

if ($user['status'] === 'suspended') {
    respond(['ok' => false, 'error' => 'This account is suspended. Contact support.'], 403);
}

$_SESSION['user_id'] = (int)$user['id'];

$redirect = 'home';
if ($user['role'] === 'admin') {
    $redirect = 'admin';
} elseif ($user['role'] === 'provider' && $user['status'] === 'needs_setup') {
    $redirect = 'setup';
} elseif ($user['role'] === 'provider') {
    $redirect = 'profile';
}

respond([
    'ok' => true,
    'message' => 'Welcome back, ' . $user['full_name'] . '!',
    'user' => public_user($user),
    'redirect' => $redirect,
]);
