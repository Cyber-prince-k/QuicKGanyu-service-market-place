<?php
// Update the logged-in user's provider profile fields.
// Username changes are allowed but must stay unique.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$user = current_user($pdo);
if ($user === null) {
    respond(['ok' => false, 'error' => 'You must be logged in to update your profile.'], 401);
}

$data = request_input();
$fullName       = str_field($data, 'full_name', $user['full_name']);
$username       = str_field($data, 'username', $user['username']);
$phone          = str_field($data, 'phone', $user['phone'] ?? '');
$location       = str_field($data, 'location', $user['location'] ?? '');
$category       = str_field($data, 'service_category', $user['service_category'] ?? '');
$skills         = str_field($data, 'skills', $user['skills'] ?? '');
$about          = str_field($data, 'about', $user['about'] ?? '');
$experienceYears = (int)($data['experience_years'] ?? ($user['experience_years'] ?? 0));

$errors = [];
if (mb_strlen($fullName) < 3) {
    $errors['full_name'] = 'Enter your full name (at least 3 characters).';
}
if (!preg_match('/^[A-Za-z0-9_.]{3,60}$/', $username)) {
    $errors['username'] = 'Username must be 3–60 characters using letters, numbers, _ or .';
} elseif (!username_available($pdo, $username, (int)$user['id'])) {
    $errors['username'] = 'That username is already taken. Please choose another.';
}
if ($experienceYears < 0 || $experienceYears > 80) {
    $errors['experience_years'] = 'Enter a valid number of years of experience.';
}
if ($errors) {
    respond(['ok' => false, 'errors' => $errors], 422);
}

$stmt = $pdo->prepare(
    'UPDATE users
        SET full_name = ?, username = ?, phone = ?, location = ?, service_category = ?,
            skills = ?, about = ?, experience_years = ?
      WHERE id = ?'
);
$stmt->execute([
    $fullName,
    $username,
    $phone ?: null,
    $location ?: null,
    $category ?: null,
    $skills ?: null,
    $about ?: null,
    $experienceYears ?: null,
    (int)$user['id'],
]);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([(int)$user['id']]);

respond([
    'ok' => true,
    'message' => 'Profile updated successfully.',
    'user' => public_user($stmt->fetch()),
]);
