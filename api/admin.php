<?php
// Admin dashboard endpoints: list requests, approve, decline.
// Auth guard: only active admins may call these.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$admin = current_user($pdo);
if ($admin === null || $admin['role'] !== 'admin' || $admin['status'] !== 'active') {
    respond(['ok' => false, 'error' => 'Admin access required. Please log in as an admin.'], 403);
}

$action = str_field(request_input(), 'action', 'list');

if ($action === 'list') {
    $stmt = $pdo->query(
        'SELECT id, full_name, email, phone, service_category, experience_years,
                skills, about, location, agreed_to_terms, status, created_at, decided_at
         FROM provider_requests
         ORDER BY CASE status WHEN "pending" THEN 0 ELSE 1 END, created_at DESC'
    );
    $requests = $stmt->fetchAll();

    $counts = [
        'pending'  => 0,
        'approved' => 0,
        'declined' => 0,
    ];
    foreach ($requests as $request) {
        if (isset($counts[$request['status']])) {
            $counts[$request['status']]++;
        }
    }

    $userStmt = $pdo->query(
        'SELECT role, COUNT(*) AS total FROM users GROUP BY role'
    );
    $roleCounts = ['admin' => 0, 'provider' => 0, 'client' => 0];
    foreach ($userStmt->fetchAll() as $row) {
        $roleCounts[$row['role']] = (int)$row['total'];
    }

    respond([
        'ok' => true,
        'requests' => $requests,
        'counts' => $counts,
        'users' => $roleCounts,
    ]);
}

$id = (int)str_field(request_input(), 'id', '0');
$note = str_field(request_input(), 'admin_note');

if ($id <= 0) {
    respond(['ok' => false, 'error' => 'Missing request id.'], 422);
}

$stmt = $pdo->prepare('SELECT * FROM provider_requests WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$request = $stmt->fetch();

if ($request === false) {
    respond(['ok' => false, 'error' => 'Request not found.'], 404);
}
if ($request['status'] !== 'pending') {
    respond(['ok' => false, 'error' => 'This request was already reviewed.'], 409);
}

if ($action === 'decline') {
    $stmt = $pdo->prepare(
        'UPDATE provider_requests
            SET status = "declined", admin_note = ?, decided_at = NOW()
          WHERE id = ?'
    );
    $stmt->execute([$note ?: null, $id]);

    send_mail(
        $request['email'],
        'QuickGanyu – Application update',
        '<p>Hello <strong>' . htmlspecialchars($request['full_name'], ENT_QUOTES) . '</strong>,</p>
         <p>Thank you for your interest in offering services on QuickGanyu.</p>
         <p>Unfortunately, your application was <strong style="color:#b00020">not approved</strong> at this time.'
         . ($note !== '' ? ' Reason: ' . htmlspecialchars($note, ENT_QUOTES) : '')
         . '</p><p>You are welcome to apply again in the future.</p>'
    );

    respond(['ok' => true, 'message' => 'Request declined. The applicant has been emailed.']);
}

if ($action !== 'approve') {
    respond(['ok' => false, 'error' => 'Unknown action.'], 422);
}

// Ensure the email is still free before creating the account.
if (!email_available($pdo, $request['email'])) {
    respond(['ok' => false, 'error' => 'The applicant email already has an account. Please decline this request.'], 409);
}

// Create provider account with a random temporary password.
$tempPassword = bin2hex(random_bytes(5));        // 10 characters
$tempHash     = password_hash($tempPassword, PASSWORD_DEFAULT);

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO users
           (full_name, email, phone, location, service_category, experience_years, skills, about,
            password_hash, role, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "provider", "needs_setup")'
    );
    $stmt->execute([
        $request['full_name'],
        $request['email'],
        $request['phone'],
        $request['location'],
        $request['service_category'],
        $request['experience_years'],
        $request['skills'],
        $request['about'],
        $tempHash,
    ]);
    $userId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'UPDATE provider_requests
            SET status = "approved", temp_password_hash = ?, user_id = ?, admin_note = ?, decided_at = NOW()
          WHERE id = ?'
    );
    $stmt->execute([$tempHash, $userId, $note ?: null, $id]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(['ok' => false, 'error' => 'Could not approve request. Please try again.'], 500);
}

send_mail(
    $request['email'],
    'QuickGanyu – Your provider account is approved',
    '<p>Hello <strong>' . htmlspecialchars($request['full_name'], ENT_QUOTES) . '</strong>,</p>
     <p>Congratulations! Your request to offer services on <strong>QuickGanyu</strong> has been approved.</p>
     <p>Your temporary login details are:</p>
     <ul>
       <li>Login name: <strong>' . htmlspecialchars($request['full_name'], ENT_QUOTES) . '</strong></li>
       <li>Temporary password: <strong>' . $tempPassword . '</strong></li>
     </ul>
     <p>Log in with your name and this temporary password, then choose a unique username and
        set your own password when you complete your profile.</p>
     <p style="color:#666">For your security, this temporary password works once. Your information is kept
        secure and is never shared with anyone else.</p>'
);

respond([
    'ok' => true,
    'message' => 'Provider approved. A temporary password has been emailed to ' . $request['email'] . '.',
    'temp_password' => $tempPassword, // shown in the admin dashboard so it can be relayed manually if email fails
]);
