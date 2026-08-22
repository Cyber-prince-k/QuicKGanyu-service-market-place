<?php
// Submit a provider application. No login required.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $data     = request_input();
    $fullName = str_field($data, 'full_name');
    $email    = str_field($data, 'email');
    $phone    = str_field($data, 'phone');
    $category = str_field($data, 'service_category');
    $location = str_field($data, 'location');
    $agreed   = !empty($data['agreed_to_terms']);

    // ── Validation ────────────────────────────────────────────────────────────
    $errors = [];
    if (mb_strlen($fullName) < 3) {
        $errors['full_name'] = 'Enter your full name (at least 3 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address so we can reach you.';
    }
    if ($category === '') {
        $errors['service_category'] = 'Select the type of services you offer.';
    }
    if (!$agreed) {
        $errors['agreed_to_terms'] = 'You must agree to the Terms & Policies to continue.';
    }
    if ($errors) {
        respond(['ok' => false, 'errors' => $errors], 422);
    }

    $pdo = db();

    if (!email_available($pdo, $email)) {
        respond(['ok' => false, 'errors' => [
            'email' => 'An account with this email already exists. Please log in instead.',
        ]], 409);
    }

    // Check for an existing pending request from the same email.
    $dup = $pdo->prepare(
        'SELECT id FROM provider_requests WHERE email = ? AND status = "pending" LIMIT 1'
    );
    $dup->execute([$email]);
    if ($dup->fetch() !== false) {
        respond(['ok' => false, 'errors' => [
            'email' => 'You already have a pending request. Please wait for the admin to review it.',
        ]], 409);
    }

    // ── Insert the request ────────────────────────────────────────────────────
    $pdo->prepare(
        'INSERT INTO provider_requests
           (full_name, email, phone, service_category, location, agreed_to_terms, status)
         VALUES (?, ?, ?, ?, ?, 1, "pending")'
    )->execute([
        $fullName,
        $email,
        $phone    ?: null,
        $category,
        $location ?: null,
    ]);

    respond([
        'ok'      => true,
        'message' => 'Thank you! Your application has been sent to our admin for review. '
                   . 'You will receive an email with your login details once approved.',
    ], 201);

} catch (RuntimeException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 503);
} catch (Throwable $e) {
    error_log('QuickGanyu offer error: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'Submission failed due to a server error. Please try again.'], 500);
}
