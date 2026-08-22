<?php
// Update the logged-in user's profile.
// Writes to users (base) + the correct child table based on role.
// Also handles optional password change.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

$pdo  = db();
$user = current_user($pdo);
if ($user === null) {
    respond(['ok' => false, 'error' => 'You must be logged in to update your profile.'], 401);
}

$data    = request_input();
$uid     = (int)$user['id'];
$errors  = [];

// ── Optional password change ────────────────────────────────────────────────
$newPassword = (string)($data['password'] ?? '');
if ($newPassword !== '') {
    if (strlen($newPassword) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }
    if ($errors) {
        respond(['ok' => false, 'errors' => $errors], 422);
    }
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $uid]);

    respond([
        'ok'      => true,
        'message' => 'Password updated successfully.',
        'user'    => public_user(fetch_user_by_id($pdo, $uid)),
    ]);
}

// ── Profile field update ────────────────────────────────────────────────────
$fullName = str_field($data, 'full_name', $user['full_name']);
$username = str_field($data, 'username',  $user['username'] ?? '');
$phone    = str_field($data, 'phone',     $user['phone']    ?? '');
$location = str_field($data, 'location',  $user['location'] ?? '');

// Validate shared fields
if (mb_strlen($fullName) < 3) {
    $errors['full_name'] = 'Full name must be at least 3 characters.';
}
// Username is required for providers; optional/skippable for customers
if ($username !== '') {
    if (!preg_match('/^[A-Za-z0-9_.]{3,60}$/', $username)) {
        $errors['username'] = 'Username: 3–60 characters, letters/numbers/_ or . only.';
    } elseif (!username_available($pdo, $username, $uid)) {
        $errors['username'] = 'That username is already taken.';
    }
}
if ($errors) {
    respond(['ok' => false, 'errors' => $errors], 422);
}

// Update base table
$pdo->prepare('UPDATE users SET full_name = ?, username = ? WHERE id = ?')
    ->execute([$fullName, $username ?: null, $uid]);

// Update child table based on role
if ($user['role'] === 'provider') {
    $category        = str_field($data, 'service_category', $user['service_category'] ?? '');
    $skills          = str_field($data, 'skills',           $user['skills']           ?? '');
    $about           = str_field($data, 'about',            $user['about']            ?? '');
    $experienceYears = isset($data['experience_years'])
        ? (int)$data['experience_years']
        : (int)($user['experience_years'] ?? 0);

    if ($experienceYears < 0 || $experienceYears > 80) {
        respond(['ok' => false, 'errors' => ['experience_years' => 'Enter a valid number (0–80).']], 422);
    }

    // Upsert into service_providers (in case the row doesn't exist yet)
    $pdo->prepare(
        'INSERT INTO service_providers (user_id, phone, location, service_category, skills, about, experience_years)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           phone = VALUES(phone),
           location = VALUES(location),
           service_category = VALUES(service_category),
           skills = VALUES(skills),
           about = VALUES(about),
           experience_years = VALUES(experience_years)'
    )->execute([
        $uid,
        $phone    ?: null,
        $location ?: null,
        $category ?: null,
        $skills   ?: null,
        $about    ?: null,
        $experienceYears ?: null,
    ]);

} elseif ($user['role'] === 'client') {
    // Upsert into customers
    $pdo->prepare(
        'INSERT INTO customers (user_id, phone, location)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE phone = VALUES(phone), location = VALUES(location)'
    )->execute([$uid, $phone ?: null, $location ?: null]);

} elseif ($user['role'] === 'admin') {
    // Upsert into admins
    $pdo->prepare(
        'INSERT INTO admins (user_id, phone, location)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE phone = VALUES(phone), location = VALUES(location)'
    )->execute([$uid, $phone ?: null, $location ?: null]);
}

respond([
    'ok'      => true,
    'message' => 'Profile updated successfully.',
    'user'    => public_user(fetch_user_by_id($pdo, $uid)),
]);
