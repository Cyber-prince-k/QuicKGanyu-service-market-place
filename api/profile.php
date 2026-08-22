<?php
// Update the logged-in user's profile fields + optional password change.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo  = db();
    $user = current_user($pdo);

    if ($user === null) {
        respond(['ok' => false, 'error' => 'You must be logged in to update your profile.'], 401);
    }

    $data = request_input();
    $uid  = (int)$user['id'];

    // ── Password-only update ──────────────────────────────────────────────────
    $newPassword = (string)($data['password'] ?? '');
    if ($newPassword !== '') {
        if (strlen($newPassword) < 8) {
            respond(['ok' => false, 'errors' => ['password' => 'Password must be at least 8 characters.']], 422);
        }
        try {
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $uid]);
        } catch (Throwable $e) {
            error_log('QuickGanyu password update error: ' . $e->getMessage());
            respond(['ok' => false, 'error' => 'Could not update password: ' . $e->getMessage()], 500);
        }
        respond([
            'ok'      => true,
            'message' => 'Password updated successfully.',
            'user'    => public_user(fetch_user_by_id($pdo, $uid)),
        ]);
    }

    // ── Profile field update ──────────────────────────────────────────────────
    $fullName = str_field($data, 'full_name', $user['full_name']);
    $username = str_field($data, 'username',  $user['username'] ?? '');
    $phone    = str_field($data, 'phone',     $user['phone']    ?? '');
    $location = str_field($data, 'location',  $user['location'] ?? '');

    $errors = [];
    if (mb_strlen($fullName) < 3) {
        $errors['full_name'] = 'Full name must be at least 3 characters.';
    }
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

    $pdo->beginTransaction();
    try {
        // Update base table
        $pdo->prepare('UPDATE users SET full_name=?, username=? WHERE id=?')
            ->execute([$fullName, $username ?: null, $uid]);

        // Update the correct child table
        if ($user['role'] === 'provider') {
            $category = str_field($data, 'service_category', $user['service_category'] ?? '');
            $skills   = str_field($data, 'skills',           $user['skills']           ?? '');
            $about    = str_field($data, 'about',            $user['about']            ?? '');
            $expYears = isset($data['experience_years'])
                ? (int)$data['experience_years']
                : (int)($user['experience_years'] ?? 0);

            if ($expYears < 0 || $expYears > 80) {
                $pdo->rollBack();
                respond(['ok' => false, 'errors' => ['experience_years' => 'Enter a valid number (0–80).']], 422);
            }

            $pdo->prepare(
                'INSERT INTO service_providers
                   (user_id, phone, location, service_category, skills, about, experience_years)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   phone            = VALUES(phone),
                   location         = VALUES(location),
                   service_category = VALUES(service_category),
                   skills           = VALUES(skills),
                   about            = VALUES(about),
                   experience_years = VALUES(experience_years)'
            )->execute([
                $uid,
                $phone    ?: null,
                $location ?: null,
                $category ?: null,
                $skills   ?: null,
                $about    ?: null,
                $expYears ?: null,
            ]);

        } elseif ($user['role'] === 'client') {
            $pdo->prepare(
                'INSERT INTO customers (user_id, phone, location) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE phone=VALUES(phone), location=VALUES(location)'
            )->execute([$uid, $phone ?: null, $location ?: null]);

        } elseif ($user['role'] === 'admin') {
            $pdo->prepare(
                'INSERT INTO admins (user_id, phone, location) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE phone=VALUES(phone), location=VALUES(location)'
            )->execute([$uid, $phone ?: null, $location ?: null]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('QuickGanyu profile update error: ' . $e->getMessage());
        respond(['ok' => false, 'error' => 'Could not save profile: ' . $e->getMessage()], 500);
    }

    respond([
        'ok'      => true,
        'message' => 'Profile updated successfully.',
        'user'    => public_user(fetch_user_by_id($pdo, $uid)),
    ]);

} catch (RuntimeException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 503);
} catch (Throwable $e) {
    error_log('QuickGanyu profile error: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'Profile update failed: ' . $e->getMessage()], 500);
}
