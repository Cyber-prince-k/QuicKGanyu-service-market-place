<?php
// Customer self-registration.
// Collects: full_name, email, phone, password.
// Username is auto-generated from the email local part to keep signup simple.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

$data     = request_input();
$fullName = str_field($data, 'full_name');
$email    = str_field($data, 'email');
$phone    = str_field($data, 'phone');
$password = (string)($data['password'] ?? '');

$errors = [];
if (mb_strlen($fullName) < 3) {
    $errors['full_name'] = 'Enter your full name (at least 3 characters).';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Enter a valid email address.';
}
if (mb_strlen($phone) < 7) {
    $errors['phone'] = 'Enter a valid phone number.';
}
if (strlen($password) < 8) {
    $errors['password'] = 'Password must be at least 8 characters.';
}

if ($errors) {
    respond(['ok' => false, 'errors' => $errors], 422);
}

$pdo = db();

if (!email_available($pdo, $email)) {
    respond(['ok' => false, 'errors' => ['email' => 'An account with this email already exists.']], 409);
}

// Derive a unique username from the email local part (e.g. john.doe@… → john_doe).
$base     = preg_replace('/[^A-Za-z0-9]/', '_', explode('@', $email)[0]);
$base     = trim($base, '_');
$base     = preg_replace('/_+/', '_', $base) ?: 'user';
$username = $base;
$suffix   = 1;
while (!username_available($pdo, $username)) {
    $username = $base . '_' . $suffix++;
}

$stmt = $pdo->prepare(
    'INSERT INTO users (full_name, username, email, phone, password_hash, role, status)
     VALUES (?, ?, ?, ?, ?, "client", "active")'
);
$stmt->execute([
    $fullName,
    $username,
    $email,
    $phone ?: null,
    password_hash($password, PASSWORD_DEFAULT),
]);

$_SESSION['user_id'] = (int)$pdo->lastInsertId();

respond([
    'ok'      => true,
    'message' => 'Account created. Welcome to QuickGanyu!',
    'user'    => public_user(current_user($pdo)),
]);
