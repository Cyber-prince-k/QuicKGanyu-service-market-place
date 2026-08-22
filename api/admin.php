<?php
// Admin dashboard: list requests, approve, decline, add_provider.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

// ── Auth guard ────────────────────────────────────────────────────────────────
try {
    $pdo   = db();
    $admin = current_user($pdo);
} catch (RuntimeException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 503);
} catch (Throwable $e) {
    error_log('QuickGanyu admin db error: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'Server error. Please try again.'], 500);
}

if ($admin === null || $admin['role'] !== 'admin' || $admin['status'] !== 'active') {
    respond(['ok' => false, 'error' => 'Admin access required. Please log in as an admin.'], 403);
}

$data   = request_input();
$action = str_field($data, 'action', 'list');

// ── LIST ──────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    try {
        $rows = $pdo->query(
            'SELECT id, full_name, email, phone, service_category,
                    status, created_at, decided_at
             FROM provider_requests
             ORDER BY CASE status WHEN "pending" THEN 0 ELSE 1 END, created_at DESC'
        )->fetchAll();

        $counts = ['pending' => 0, 'approved' => 0, 'declined' => 0];
        foreach ($rows as $r) {
            if (isset($counts[$r['status']])) $counts[$r['status']]++;
        }

        $roleCounts = ['admin' => 0, 'provider' => 0, 'client' => 0];
        foreach ($pdo->query('SELECT role, COUNT(*) AS total FROM users GROUP BY role')->fetchAll() as $r) {
            $roleCounts[$r['role']] = (int)$r['total'];
        }

        respond(['ok' => true, 'requests' => $rows, 'counts' => $counts, 'users' => $roleCounts]);
    } catch (Throwable $e) {
        error_log('QuickGanyu admin list error: ' . $e->getMessage());
        respond(['ok' => false, 'error' => 'Could not load dashboard data.'], 500);
    }
}

// ── ADD PROVIDER (admin creates a provider directly) ──────────────────────────
if ($action === 'add_provider') {
    try {
        $fullName = str_field($data, 'full_name');
        $email    = str_field($data, 'email');
        $phone    = str_field($data, 'phone');
        $location = str_field($data, 'location');
        $category = str_field($data, 'service_category');
        $expYears = (int)($data['experience_years'] ?? 0);
        $skills   = str_field($data, 'skills');
        $about    = str_field($data, 'about');

        $errors = [];
        if (mb_strlen($fullName) < 3)             $errors['full_name']        = 'Enter full name (at least 3 characters).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email']      = 'Enter a valid email address.';
        if ($category === '')                      $errors['service_category'] = 'Select a service category.';
        if ($expYears < 0 || $expYears > 80)       $errors['experience_years'] = 'Enter valid years (0–80).';
        if ($errors) respond(['ok' => false, 'errors' => $errors], 422);

        if (!email_available($pdo, $email, 'provider')) {
            respond(['ok' => false, 'errors' => ['email' => 'A provider account with this email already exists.']], 409);
        }

        $tempPassword = bin2hex(random_bytes(5));
        $tempHash     = password_hash($tempPassword, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            // 1. Base row — users only holds identity fields
            $pdo->prepare(
                'INSERT INTO users (full_name, email, password_hash, role, status)
                 VALUES (?, ?, ?, "provider", "needs_setup")'
            )->execute([$fullName, $email, $tempHash]);
            $userId = (int)$pdo->lastInsertId();

            // 2. Child row in service_providers — all professional profile fields go here
            $pdo->prepare(
                'INSERT INTO service_providers
                   (user_id, phone, location, service_category, experience_years, skills, about)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $userId,
                $phone    ?: null,
                $location ?: null,
                $category,
                $expYears ?: null,
                $skills   ?: null,
                $about    ?: null,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('QuickGanyu add_provider insert error: ' . $e->getMessage());
            respond(['ok' => false, 'error' => 'Could not create provider account: ' . $e->getMessage()], 500);
        }

        send_mail($email, 'Welcome to QuickGanyu – Your provider account is ready',
            '<h1>Your provider account is ready!</h1>
             <p>Hello <strong>' . htmlspecialchars($fullName, ENT_QUOTES) . '</strong>,</p>
             <p>An admin has created a provider account for you on <strong>QuickGanyu</strong>.</p>
             <div class="info-box">
               <div class="label">Login with your email</div>
               <div class="value" style="font-size:15px;">' . htmlspecialchars($email, ENT_QUOTES) . '</div>
               <div style="margin-top:14px;">
                 <div class="label">Temporary password</div>
                 <div class="value">' . htmlspecialchars($tempPassword, ENT_QUOTES) . '</div>
               </div>
             </div>
             <p>Log in and complete your profile setup to choose a username and set your own password.</p>
             <a class="cta-btn" href="http://localhost/roi/html/login.html">Log in to QuickGanyu</a>
             <div class="notice"><strong>Keep this password safe.</strong> It expires once you complete setup.</div>'
        );

        respond([
            'ok'            => true,
            'message'       => 'Provider account created. Temporary password sent to ' . $email . '.',
            'temp_password' => $tempPassword,
        ], 201);

    } catch (Throwable $e) {
        error_log('QuickGanyu add_provider error: ' . $e->getMessage());
        respond(['ok' => false, 'error' => 'Could not create provider: ' . $e->getMessage()], 500);
    }
}

// ── APPROVE / DECLINE ─────────────────────────────────────────────────────────
$id   = (int)str_field($data, 'id', '0');
$note = str_field($data, 'admin_note');

if ($id <= 0) respond(['ok' => false, 'error' => 'Missing request id.'], 422);

try {
    $stmt = $pdo->prepare('SELECT * FROM provider_requests WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $request = $stmt->fetch();
} catch (Throwable $e) {
    error_log('QuickGanyu admin fetch request error: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'Could not fetch request.'], 500);
}

if ($request === false) respond(['ok' => false, 'error' => 'Request not found.'], 404);
if ($request['status'] !== 'pending') respond(['ok' => false, 'error' => 'This request was already reviewed.'], 409);

// ── DECLINE ───────────────────────────────────────────────────────────────────
if ($action === 'decline') {
    try {
        $pdo->prepare(
            'UPDATE provider_requests SET status="declined", admin_note=?, decided_at=NOW() WHERE id=?'
        )->execute([$note ?: null, $id]);
    } catch (Throwable $e) {
        error_log('QuickGanyu decline error: ' . $e->getMessage());
        respond(['ok' => false, 'error' => 'Could not decline request.'], 500);
    }

    send_mail($request['email'], 'QuickGanyu – We reviewed your application',
        '<h1>Application update</h1>
         <p>Hello <strong>' . htmlspecialchars($request['full_name'], ENT_QUOTES) . '</strong>,</p>
         <p>Thank you for your interest in offering services on <strong>QuickGanyu</strong>.</p>
         <div class="declined-box">
           <div class="label">Application status</div>
           <div class="value">Not approved at this time</div>
         </div>'
        . ($note !== '' ? '<p><strong>Reason:</strong> ' . htmlspecialchars($note, ENT_QUOTES) . '</p>' : '')
        . '<div class="notice">You are welcome to reapply in the future.</div>'
    );

    respond(['ok' => true, 'message' => 'Request declined. The applicant has been notified.']);
}

if ($action !== 'approve') respond(['ok' => false, 'error' => 'Unknown action.'], 422);

// ── APPROVE ───────────────────────────────────────────────────────────────────
if (!email_available($pdo, $request['email'], 'provider')) {
    respond(['ok' => false, 'error' => 'The applicant already has a provider account. Please decline this request.'], 409);
}

$tempPassword = bin2hex(random_bytes(5));
$tempHash     = password_hash($tempPassword, PASSWORD_DEFAULT);

$pdo->beginTransaction();
try {
    // 1. Insert base row into users
    $pdo->prepare(
        'INSERT INTO users (full_name, email, password_hash, role, status)
         VALUES (?, ?, ?, "provider", "needs_setup")'
    )->execute([$request['full_name'], $request['email'], $tempHash]);
    $userId = (int)$pdo->lastInsertId();

    // 2. Insert child row into service_providers
    $pdo->prepare(
        'INSERT INTO service_providers
           (user_id, phone, location, service_category, experience_years, skills, about)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $userId,
        $request['phone']            ?: null,
        $request['location']         ?: null,
        $request['service_category'] ?: null,
        $request['experience_years'] ?: null,
        $request['skills']           ?: null,
        $request['about']            ?: null,
    ]);

    // 3. Mark the request as approved and link to the new user
    $pdo->prepare(
        'UPDATE provider_requests
            SET status="approved", temp_password_hash=?, user_id=?, admin_note=?, decided_at=NOW()
          WHERE id=?'
    )->execute([$tempHash, $userId, $note ?: null, $id]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('QuickGanyu approve error: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'Could not approve request: ' . $e->getMessage()], 500);
}

send_mail($request['email'], 'Congratulations – Your QuickGanyu provider account is approved!',
    '<h1>You\'re approved! 🎉</h1>
     <p>Hello <strong>' . htmlspecialchars($request['full_name'], ENT_QUOTES) . '</strong>,</p>
     <p>Your application to offer services on <strong>QuickGanyu</strong> has been approved.</p>
     <div class="info-box">
       <div class="label">Login with your email</div>
       <div class="value" style="font-size:15px;">' . htmlspecialchars($request['email'], ENT_QUOTES) . '</div>
       <div style="margin-top:14px;">
         <div class="label">Temporary password</div>
         <div class="value">' . htmlspecialchars($tempPassword, ENT_QUOTES) . '</div>
       </div>
     </div>
     <p>Log in with your email and the temporary password above, then set your own username and password.</p>
     <a class="cta-btn" href="http://localhost/roi/html/login.html">Log in &amp; complete your profile</a>
     <div class="notice"><strong>Keep this password safe.</strong> It expires once you complete setup.</div>'
);

respond([
    'ok'            => true,
    'message'       => 'Provider approved. A temporary password has been emailed to ' . $request['email'] . '.',
    'temp_password' => $tempPassword,
]);
