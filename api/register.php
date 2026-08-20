<?php
// Client self-registration.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

$data = request_input();
$fullName = str_field($data, 'full_name');
$username = str_field($data, 'username');
$email    = str_field($data, 'email');
$phone    = str_field($data, 'phone');
$password = (string)($data['password'] ?? '');
$location = str_field($data, 'location');

$errors = [];
if (mb_strlen($fullName) < 3) {
    $errors['full_name'] = 'Enter your full name (at least 3 characters).';
}
if (!preg_match('/^[A-Za-z0-9_.]{3,60}$/', $username)) {
    $errors['username'] = 'Username must be 3–60 characters using letters, numbers, _ or .';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Enter a valid email address.';
}
if (strlen($password) < 8) {
    $errors['password'] = 'Password must be at least 8 characters.';
}

$pdo = db();
if (!$errors && !username_available($pdo, $username)) {
    $errors['username'] = 'That username is already taken. Please choose another.';
}
if (!$errors && !email_available($pdo, $email)) {
    $errors['email'] = 'An account with this email already exists.';
}

if ($errors) {
    respond(['ok' => false, 'errors' => $errors], 422);
}

$stmt = $pdo->prepare(
    'INSERT INTO users (full_name, username, email, phone, location, password_hash, role, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $fullName,
    $username,
    $email,
    $phone ?: null,
    $location ?: null,
    password_hash($password, PASSWORD_DEFAULT),
    'client',
    'active',
]);

$userId = (int)$pdo->lastInsertId();
$_SESSION['user_id'] = $userId;

respond([
    'ok' => true,
    'message' => 'Account created. Welcome to QuickGanyu!',
    'user' => public_user(current_user($pdo)),
]);
