<?php
// Submit a "Offer services" request. An admin reviews it in the dashboard.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

$data = request_input();
$fullName        = str_field($data, 'full_name');
$email           = str_field($data, 'email');
$phone           = str_field($data, 'phone');
$category        = str_field($data, 'service_category');
$experienceYears = (int)($data['experience_years'] ?? 0);
$skills          = str_field($data, 'skills');
$about           = str_field($data, 'about');
$location        = str_field($data, 'location');
$agreed          = !empty($data['agreed_to_terms']);

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
if ($experienceYears < 0 || $experienceYears > 80) {
    $errors['experience_years'] = 'Enter a valid number of years of experience.';
}
if (!$agreed) {
    $errors['agreed_to_terms'] = 'You must agree to the Terms & Policies to continue.';
}

$pdo = db();
if (!$errors) {
    if (!email_available($pdo, $email)) {
        $errors['email'] = 'An account with this email already exists. Please log in instead.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id FROM provider_requests WHERE email = ? AND status = ? LIMIT 1'
        );
        $stmt->execute([$email, 'pending']);
        if ($stmt->fetch() !== false) {
            $errors['email'] = 'You already have a pending request. Please wait for the admin to review it.';
        }
    }
}

if ($errors) {
    respond(['ok' => false, 'errors' => $errors], 422);
}

$stmt = $pdo->prepare(
    'INSERT INTO provider_requests
       (full_name, email, phone, service_category, experience_years, skills, about, location, agreed_to_terms, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
);
$stmt->execute([
    $fullName,
    $email,
    $phone ?: null,
    $category,
    $experienceYears ?: null,
    $skills ?: null,
    $about ?: null,
    $location ?: null,
    'pending',
]);

respond([
    'ok' => true,
    'message' => 'Thank you! Your request to offer services has been sent to our admin for review. '
        . 'You will receive an email with your temporary login details once approved.',
], 201);
