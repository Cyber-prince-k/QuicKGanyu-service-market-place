<?php
// Login — works for admins, service providers and customers.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

$data       = request_input();
$identifier = strtolower(str_field($data, 'identifier')); // username OR email OR full name
$password   = (string)($data['password'] ?? '');

if ($identifier === '' || $password === '') {
    respond(['ok' => false, 'error' => 'Enter both your username/email and password.'], 422);
}

$pdo  = db();
$stmt = $pdo->prepare(
    'SELECT * FROM users
      WHERE LOWER(username) = ?
         OR LOWER(email)    = ?
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

// Merge child-table data for the response.
$user = fetch_user_by_id($pdo, (int)$base['id']);

// Determine where to send the user after login.
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
