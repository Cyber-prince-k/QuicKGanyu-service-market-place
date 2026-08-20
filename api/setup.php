<?php
// Provider first-login setup: choose a unique username and set a new password.
// Requires the provider to be logged in with status = needs_setup.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
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

$data = request_input();
$username = str_field($data, 'username');
$password = (string)($data['password'] ?? '');
$phone    = str_field($data, 'phone');
$location = str_field($data, 'location');

$errors = [];
if (!preg_match('/^[A-Za-z0-9_.]{3,60}$/', $username)) {
    $errors['username'] = 'Username must be 3–60 characters using letters, numbers, _ or .';
} elseif (!username_available($pdo, $username, (int)$user['id'])) {
    $errors['username'] = 'That username is already taken. Please choose another.';
}
if (strlen($password) < 8) {
    $errors['password'] = 'Password must be at least 8 characters.';
}
if ($errors) {
    respond(['ok' => false, 'errors' => $errors], 422);
}

$stmt = $pdo->prepare(
    'UPDATE users
        SET username = ?, password_hash = ?, status = "active", phone = COALESCE(?, phone), location = COALESCE(?, location)
      WHERE id = ?'
);
$stmt->execute([
    $username,
    password_hash($password, PASSWORD_DEFAULT),
    $phone ?: null,
    $location ?: null,
    (int)$user['id'],
]);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([(int)$user['id']]);

respond([
    'ok' => true,
    'message' => 'Setup complete! Your provider account is now active.',
    'user' => public_user($stmt->fetch()),
    'redirect' => 'profile',
]);
